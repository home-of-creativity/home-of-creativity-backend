<?php

namespace App\Actions;

use App\Enums\ClickUpSyncEvent;
use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\DriveDelivery;
use App\Models\PhotographyBooking;
use App\Models\PricingPackage;
use App\Models\RequestFile;
use App\Models\ServiceRequest;
use App\Services\GeminiService;
use App\Services\RequestStatusTransitionService;
use App\Services\SiteGuide;
use App\Services\TelegramNotifier;
use App\Contracts\WhatsAppMessenger;
use App\Support\BillingPeriod;
use App\Support\PaymentPlanResolver;
use App\Support\ChatLanguage;
use App\Support\ClientChannelGate;
use App\Support\ClientProfileValue;
use App\Support\PricingCatalog;
use App\Support\ResolveServiceRequest;
use App\Support\StatusLabel;
use App\Support\WorkCalendar;
use App\Support\WorkLines;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class HandleWhatsAppInbound
{
    private const SESSION_TTL_SECONDS = 7 * 24 * 3600;

    private const SUPPORT_PHONE = ClientChannelGate::SUPPORT_PHONE;

    /** @var list<string> */
    private const NAV_NEW = ['طلب جديد', '🆕 طلب جديد', 'menu:new', 'New request', 'new request'];

    /** @var list<string> */
    private const NAV_MINE = ['طلباتي', '📋 طلباتي', 'menu:mine', 'My requests', 'my requests'];

    /** @var list<string> */
    private const NAV_HELP = ['الدعم', '💬 دعم', 'menu:help', 'Support', 'support'];

    /** @var list<string> */
    private const NAV_ASK = ['استفسار', 'menu:ask', 'Inquiry', 'inquiry'];

    /** @var list<string> */
    private const NAV_PROFILE = ['بياناتي', 'menu:profile', 'My details', 'my details'];

    /** @var list<string> */
    private const NAV_PHOTO = ['حجز تصوير', 'موعد تصوير', 'menu:photo', 'Photo booking'];

    /** @var list<string> */
    private const NAV_RENEW = ['تجديد الاشتراك', 'menu:renew', 'Renew subscription'];

    private string $replyLang = 'ar';

    public function __construct(
        private ResolveTelegramClient $resolveTelegramClient,
        private TelegramNotifier $telegram,
        private WhatsAppMessenger $whatsApp,
        private PricingCatalog $pricingCatalog,
        private CreateCatalogRequest $createCatalogRequest,
        private SubmitServiceRequest $submitServiceRequest,
        private ApproveQuotation $approveQuotation,
        private RejectQuotation $rejectQuotation,
        private RequestRevision $requestRevision,
        private ApproveDriveDelivery $approveDriveDelivery,
        private CompleteRequest $completeRequest,
        private RenewSubscription $renewSubscription,
        private DeclineRenewal $declineRenewal,
        private NotifyEmployees $notifyEmployees,
        private NotifyPaymentStage $notifyPaymentStage,
        private ProvisionSalesClickUpTask $provisionSalesClickUpTask,
        private PushClientToOdoo $pushClientToOdoo,
        private PushClientLeadToOdoo $pushClientLeadToOdoo,
        private ResolveServiceRequest $resolveServiceRequest,
        private RequestStatusTransitionService $transitions,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        if (ClientChannelGate::whatsappLocked()) {
            return;
        }

        foreach ($this->messagesFrom($payload) as $item) {
            $this->processMessage($item['phone'], $item['profile_name'], $item['message']);
        }
    }

    /**
     * @return list<array{phone: string, profile_name: string, message: array<string, mixed>}>
     */
    private function messagesFrom(array $payload): array
    {
        $out = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            foreach ($entry['changes'] ?? [] as $change) {
                if (! is_array($change)) {
                    continue;
                }
                $value = $change['value'] ?? [];
                if (! is_array($value) || ! is_array($value['messages'] ?? null)) {
                    continue;
                }
                $profileName = (string) data_get($value, 'contacts.0.profile.name', '');
                foreach ($value['messages'] as $message) {
                    if (! is_array($message)) {
                        continue;
                    }
                    $phone = Client::normalizeWhatsAppPhone((string) ($message['from'] ?? ''));
                    if ($phone === '') {
                        continue;
                    }
                    $out[] = [
                        'phone' => $phone,
                        'profile_name' => $profileName,
                        'message' => $message,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function processMessage(string $phone, string $profileName, array $message): void
    {
        $wamid = (string) ($message['id'] ?? '');
        if ($wamid !== '' && ! Cache::add('hoc:wa-msg:'.$wamid, 1, now()->addDay())) {
            return;
        }

        if (! ClientChannelGate::whatsappEnabled()) {
            $this->whatsApp->markRead($wamid);
            $this->replyPausedOnce($phone);

            return;
        }

        $calendar = app(WorkCalendar::class);
        if (! $calendar->isWhatsAppOpen()) {
            $this->whatsApp->markRead($wamid);
            $this->replyClosedOnce($phone, $calendar);

            return;
        }

        $this->whatsApp->markRead($wamid);

        $chatId = Client::whatsappKey($phone);
        $client = $this->ensureClient($chatId, $phone, $profileName);
        $session = $this->session($phone);

        try {
            $callback = $this->callbackId($message);
            $text = $this->messageText($message);
            $media = $this->messageMedia($message);
            $voice = $this->voiceNote($message);
            if ($voice !== null) {
                $spoken = app(GeminiService::class)->transcribeClientAudio($voice['mime_type'], $voice['file_base64']);
                if ($spoken === '') {
                    $this->safeSend($chatId, $this->tx('ما قدرت أفهم التسجيل. عيده أو اكتبه.', 'I could not understand the recording. Send it again or type it.'));

                    return;
                }
                $text = $spoken;
            }
            $this->rememberLanguage($callback === null ? $text : null, $phone, $session, $client);

            if ($callback !== null) {
                $this->handleCallback($client, $chatId, $phone, $session, $callback);

                return;
            }

            if ($this->isNav($text, self::NAV_NEW)) {
                if (($session['step'] ?? '') === 'quote') {
                    $this->showQuoteDecision($chatId, $phone, $session);

                    return;
                }
                $this->resetCompose($session);
                $this->putSession($phone, $session);
                if (! $this->gateProfile($client, $chatId, $session)) {
                    return;
                }
                $this->showCatalog($client, $chatId, $phone, $session);

                return;
            }
            if ($this->isNav($text, self::NAV_MINE)) {
                $this->resetCompose($session);
                $this->putSession($phone, $session);
                $this->listRequests($client, $chatId);

                return;
            }
            if ($this->isNav($text, self::NAV_PROFILE)) {
                $this->showProfile($client, $chatId, $phone, $session);

                return;
            }
            if ($this->isNav($text, self::NAV_HELP)) {
                $this->resetCompose($session);
                $this->putSession($phone, $session);
                $this->sendMenu($chatId, $this->tx('احكي معنا عالرقم: ', 'Call us: ').self::SUPPORT_PHONE);

                return;
            }
            if ($this->isNav($text, self::NAV_ASK)) {
                $this->resetCompose($session);
                $this->beginAsk($chatId, $phone, $session);

                return;
            }
            if ($this->isNav($text, self::NAV_PHOTO)) {
                $this->offerPhotography($client, $chatId, $phone, $session);

                return;
            }
            if ($this->isNav($text, self::NAV_RENEW)) {
                $this->startRenewal($client, $chatId);

                return;
            }

            $step = (string) ($session['step'] ?? 'idle');
            if (in_array($step, ['name', 'phone', 'company_name'], true)) {
                $this->captureProfile($client, $chatId, $phone, $session, $text);

                return;
            }
            if ($step === 'profile_edit') {
                $this->saveProfileEdit($client, $chatId, $phone, $session, $text);

                return;
            }
            if ($step === 'title') {
                $this->captureTitle($client, $chatId, $phone, $session, $text);

                return;
            }
            if ($step === 'body') {
                $this->captureBody($client, $chatId, $phone, $session, $text, $media);

                return;
            }
            if ($step === 'reject_reason') {
                $this->finishReject($client, $chatId, $phone, $session, $text);

                return;
            }
            if ($step === 'revision') {
                $this->finishRevision($client, $chatId, $phone, $session, $text);

                return;
            }
            if ($step === 'receipt' && $media !== null) {
                $this->storeReceipt($client, $chatId, $phone, $session, $media);

                return;
            }
            if ($step === 'ask') {
                $this->answerAsk($chatId, $phone, $session, (string) $text);

                return;
            }
            if ($step === 'edit_title') {
                $this->captureEditTitle($chatId, $phone, $session, (string) $text);

                return;
            }
            if ($step === 'edit_body') {
                $this->captureEditBody($client, $chatId, $phone, $session, (string) $text);

                return;
            }
            if ($step === 'hours_pick') {
                $this->pickRequestHours($client, $chatId, $phone, $session, (string) $text);

                return;
            }
            if ($step === 'photo_day' && preg_match('/^(?:10|[1-9])$/u', trim((string) $text)) === 1) {
                $this->pickPhotoDay($client, $chatId, $phone, $session, (int) $text);

                return;
            }
            if (in_array($step, ['photo_pick', 'photo_time'], true) && preg_match('/^(?:10|[1-9])$/u', trim((string) $text)) === 1) {
                $this->bookPhotoSlot($client, $chatId, $phone, $session, (int) $text);

                return;
            }
            if (in_array($step, ['quote', 'suggest', 'receipt', 'ask'], true) || $step === 'idle' || $step === '') {
                if ($this->routeSentence($client, $chatId, $phone, $session, (string) $text)) {
                    return;
                }
            }
            if ($step === 'quote') {
                $this->continueQuote($client, $chatId, $phone, $session, (string) $text);

                return;
            }
            if ($step === 'suggest') {
                $this->continueSuggest($client, $chatId, $phone, $session, (string) $text);

                return;
            }
            if ($step === 'receipt') {
                $this->safeSend($chatId, $this->tx('ابعت صورة وصل الدفع أو ملف PDF.', 'Waiting for the payment receipt. Send a photo of the transfer or a PDF.'));

                return;
            }
            if ($step === 'ask') {
                $this->answerAsk($chatId, $phone, $session, (string) $text);

                return;
            }

            $asking = $this->looksLikeQuestion((string) $text);
            if (! $this->gateProfile($client, $chatId, $session, greet: ! $asking)) {
                return;
            }

            if ($media !== null && $this->pendingReceiptRequest($client) !== null) {
                $session['step'] = 'receipt';
                $session['receipt_ref'] = ResolveServiceRequest::displayNumber($this->pendingReceiptRequest($client));
                $this->storeReceipt($client, $chatId, $phone, $session, $media);

                return;
            }

            if ($asking) {
                $this->answerAsk($chatId, $phone, $session, (string) $text);
            }
        } catch (Throwable $exception) {
            Log::error('WhatsApp conversation failed.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);
            $this->safeSend($chatId, $this->tx('صار في مشكلة صغيرة. جرب مرة تانية أو احكي مع الدعم.', 'That could not be completed. Try again or choose Support.'));
        }
    }

    private function replyPausedOnce(string $phone): void
    {
        if (! Cache::add('hoc:wa-paused-notice:'.$phone, 1, now()->addMinutes(30))) {
            return;
        }

        try {
            $session = $this->session($phone);
            $this->replyLang = ($session['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
            $this->whatsApp->sendText($phone, $this->tx(
                ClientChannelGate::WHATSAPP_PAUSED_MESSAGE,
                'WhatsApp is quiet for a bit. Try again later.',
            ));
        } catch (Throwable $exception) {
            Log::info('WhatsApp pause notice skipped.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function replyClosedOnce(string $phone, WorkCalendar $calendar): void
    {
        $until = $calendar->nextWhatsAppOpen();
        if (! Cache::add('hoc:wa-closed-notice:'.$phone, 1, $until)) {
            return;
        }

        try {
            $session = $this->session($phone);
            $this->replyLang = ($session['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
            $this->whatsApp->sendText($phone, $this->tx(
                $calendar->whatsappClosedNotice(),
                "We're not here on WhatsApp right now. Message us later and we'll get back to you.",
            ));
        } catch (Throwable $exception) {
            Log::info('WhatsApp closed-hours notice skipped.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function ensureClient(string $chatId, string $phone, string $profileName): Client
    {
        $existing = Client::findForTelegram($chatId);
        if ($existing !== null) {
            return $this->resolveTelegramClient->handle($chatId);
        }

        $name = ClientProfileValue::usableName($profileName) ?? ($profileName !== '' ? $profileName : 'عميل واتساب');

        return $this->resolveTelegramClient->link($chatId, [
            'name' => $name,
            'phone' => '+'.$phone,
            'locale' => 'ar',
        ]);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function gateProfile(Client $client, string $chatId, array &$session, bool $greet = false): bool
    {
        $client = $client->fresh() ?? $client;
        $missing = $client->missingProfileFields();
        if ($missing === []) {
            if ($greet) {
                $this->sendMenu(
                    $chatId,
                    $this->tx(
                        'أهلين '.$client->name."، كيف فيني ساعدك؟\nفيك تطلب خدمة، تشوف طلباتك، تحجز تصوير، أو تجدد اشتراكك.",
                        'Hello '.$client->name.".\nYou can request a service, check your orders, book a shoot, or renew a subscription.",
                    ),
                );
            }

            return true;
        }

        $field = $missing[0];
        $session['step'] = $field;
        $this->putSession(Client::whatsappPhoneFromKey($chatId), $session);

        $intro = $greet
            ? $this->tx(
                'أهلين '.($client->name ?: '')."، نورت.\nقبل ما نبلش بدنا اسمك وشركتك، عشان نجهزلك الطلب صح.\n",
                'Hello '.($client->name ?: '').".\nBefore the first request we need your name and company.\n",
            )
            : '';

        $prompt = match ($field) {
            'name' => $this->tx('شو اسمك؟', 'What is your full name?'),
            'phone' => $this->tx(
                "عطينا رقمك لحتى نرجعلك لما يجهز العرض.\nشو رقمك؟",
                "We ask for a phone number so we can reach you when the quotation is ready.\nWhat is your phone number?",
            ),
            default => $this->tx(
                "شو اسم الشركة أو المحل؟ منحط العرض والفاتورة عاسمه.\n",
                "We ask for the company name so the quotation and invoice use it.\nWhat is the company name?",
            ),
        };

        $this->safeSend($chatId, $intro.$prompt);

        return false;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function captureProfile(Client $client, string $chatId, string $phone, array $session, ?string $text): void
    {
        $field = (string) ($session['step'] ?? '');
        $value = trim((string) $text);
        if ($value === '' || ClientProfileValue::isKeyboardLabel($value)) {
            $this->gateProfile($client, $chatId, $session);

            return;
        }

        if ($field === 'name' && ClientProfileValue::usableName($value) === null) {
            $this->safeSend($chatId, $this->tx('اكتب اسمك، مو رقم ولا خيار من القائمة.', 'Send your full name, not a number or a menu button.'));

            return;
        }
        if ($field === 'phone' && ClientProfileValue::usablePhone($value) === null) {
            $this->safeSend($chatId, $this->tx('الرقم مو واضح، ابعت رقم الموبايل.', 'Send a valid phone number.'));

            return;
        }
        if ($field === 'company_name' && ClientProfileValue::usableCompanyName($value, $chatId) === null) {
            $this->safeSend($chatId, $this->tx('اكتب اسم الشركة أو المحل.', 'Send the real company name.'));

            return;
        }

        $column = $field;
        $client->forceFill([$column => $value])->save();
        $client = $client->fresh() ?? $client;
        $this->pushCompletedClientToOdoo($client);

        $session['step'] = 'idle';
        $this->putSession($phone, $session);
        $client = $client->fresh() ?? $client;
        if (! $this->gateProfile($client, $chatId, $session)) {
            return;
        }

        $this->sendMenu($chatId, $this->tx('تمام، سجلنا بياناتك. شو بدك نساوي؟', 'Your details are saved. Reply with the option number.'));
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function handleCallback(Client $client, string $chatId, string $phone, array $session, string $data): void
    {
        if ($data === 'menu:home') {
            if (filled($session['quote_ref'] ?? null)) {
                $this->showQuoteDecision($chatId, $phone, $session);

                return;
            }
            $session['step'] = 'idle';
            unset($session['profile_field']);
            $this->putSession($phone, $session);
            $this->sendMenu($chatId, $this->tx(
                'شو بدك نساوي؟ فيك تطلب، تشوف طلباتك، تحجز تصوير، أو تجدد اشتراكك.',
                'What do you need? You can request, check your orders, book a shoot, or renew.',
            ));

            return;
        }
        if ($this->isNav($data, self::NAV_PROFILE) || $data === 'menu:profile') {
            $this->showProfile($client, $chatId, $phone, $session);

            return;
        }
        if (str_starts_with($data, 'prof:')) {
            $field = substr($data, 5);
            if (! in_array($field, ['name', 'phone', 'company_name'], true)) {
                $this->showProfile($client, $chatId, $phone, $session);

                return;
            }
            $session['step'] = 'profile_edit';
            $session['profile_field'] = $field;
            $this->putSession($phone, $session);
            $prompt = match ($field) {
                'name' => $this->tx('شو الاسم الجديد؟', 'What is the new full name?'),
                'phone' => $this->tx('شو الرقم الجديد؟', 'What is the new phone number?'),
                default => $this->tx('شو اسم الشركة الجديد؟', 'What is the new company name?'),
            };
            $this->safeSend($chatId, $prompt);

            return;
        }
        if ($this->isNav($data, self::NAV_NEW) || $data === 'menu:new') {
            if (($session['step'] ?? '') === 'quote') {
                $this->showQuoteDecision($chatId, $phone, $session);

                return;
            }
            if (($session['step'] ?? '') === 'suggest') {
                $session['step'] = 'idle';
                unset($session['suggest_package'], $session['suggest_period']);
            }
            if (! $this->gateProfile($client, $chatId, $session)) {
                return;
            }
            $this->showCatalog($client, $chatId, $phone, $session);

            return;
        }
        if ($this->isNav($data, self::NAV_MINE) || $data === 'menu:mine') {
            $this->listRequests($client, $chatId);

            return;
        }
        if ($this->isNav($data, self::NAV_HELP) || $data === 'menu:help') {
            $this->sendMenu($chatId, $this->tx('احكي معنا عالرقم: ', 'Call us: ').self::SUPPORT_PHONE);

            return;
        }
        if ($this->isNav($data, self::NAV_ASK) || $data === 'menu:ask') {
            $this->beginAsk($chatId, $phone, $session);

            return;
        }
        if ($this->isNav($data, self::NAV_PHOTO) || $data === 'menu:photo') {
            $this->offerPhotography($client, $chatId, $phone, $session);

            return;
        }
        if ($this->isNav($data, self::NAV_RENEW) || $data === 'menu:renew') {
            $this->startRenewal($client, $chatId);

            return;
        }
        if (str_starts_with($data, 'photo_day:')) {
            $this->pickPhotoDay($client, $chatId, $phone, $session, (int) substr($data, 10) + 1);

            return;
        }
        if (str_starts_with($data, 'photo_time:')) {
            $this->bookPhotoSlot($client, $chatId, $phone, $session, (int) substr($data, 11) + 1);

            return;
        }
        if (str_starts_with($data, 'open:')) {
            $this->showRequest($client, $chatId, substr($data, 5));

            return;
        }
        if (str_starts_with($data, 'hours:')) {
            $this->showTaskHours($client, $chatId, $phone, $session, substr($data, 6));

            return;
        }
        if (str_starts_with($data, 'more_hours:')) {
            $this->askRequestHours($client, $chatId, $phone, $session, (int) substr($data, 11));

            return;
        }
        if (str_starts_with($data, 'edit:')) {
            $this->beginEdit($client, $chatId, $phone, $session, substr($data, 5));

            return;
        }
        if (str_starts_with($data, 'ph:')) {
            $this->bookPhotoSlot($client, $chatId, $phone, $session, (int) substr($data, 3) + 1);

            return;
        }
        if (str_starts_with($data, 'photoyes:') || str_starts_with($data, 'photonno:')) {
            $number = explode(':', $data, 2)[1] ?? '';
            $accept = str_starts_with($data, 'photoyes:');
            $this->runOwned($client, $number, function (ServiceRequest $request) use ($chatId, $accept): void {
                $booking = app(BookPhotographySlot::class)->decide($request, $accept);
                $this->safeSend($chatId, app(BookPhotographySlot::class)->clientMessage($booking));
            });

            return;
        }
        if ($data === 'back') {
            $this->catalogBack($client, $chatId, $phone, $session);

            return;
        }
        if ($data === 'cman') {
            if (! $this->gateProfile($client, $chatId, $session)) {
                return;
            }
            $session['step'] = 'title';
            $session['attachments'] = [];
            $session['description'] = '';
            $this->putSession($phone, $session);
            $this->telegram->sendInlineKeyboard($chatId, $this->tx('شو عنوان الطلب؟', 'What is the request title?'), [[
                ['text' => $this->tx('السابق', 'Back'), 'callback_data' => 'back'],
            ]]);

            return;
        }
        if (str_starts_with($data, 'cat:')) {
            $session['catalog_stack'][] = ['kind' => 'cat', 'id' => (int) substr($data, 4)];
            $this->showCatalog($client, $chatId, $phone, $session, categoryId: (int) substr($data, 4));

            return;
        }
        if (str_starts_with($data, 'sub:')) {
            $session['catalog_stack'][] = ['kind' => 'sub', 'id' => (int) substr($data, 4)];
            $this->showCatalog($client, $chatId, $phone, $session, subcategoryId: (int) substr($data, 4));

            return;
        }
        if (str_starts_with($data, 'pkg:')) {
            $session['catalog_stack'][] = ['kind' => 'pkg', 'id' => (int) substr($data, 4)];
            $this->showCatalog($client, $chatId, $phone, $session, packageId: (int) substr($data, 4));

            return;
        }
        if (str_starts_with($data, 'per:')) {
            $parts = explode(':', $data, 3);
            $this->createCatalog($client, $chatId, $phone, $session, (int) ($parts[1] ?? 0), $parts[2] ?? null);

            return;
        }
        if (str_starts_with($data, 'pick:')) {
            $parts = explode(':', $data, 3);
            $this->createCatalog($client, $chatId, $phone, $session, (int) ($parts[1] ?? 0), $parts[2] ?? null);

            return;
        }
        if (str_starts_with($data, 'more:')) {
            $parts = explode(':', $data);
            $scope = $parts[1] ?? 'root';
            $parent = (int) ($parts[2] ?? 0);
            $offset = (int) ($parts[3] ?? 0);
            $this->showCatalog(
                $client,
                $chatId,
                $phone,
                $session,
                categoryId: $scope === 'cat' ? $parent : null,
                subcategoryId: $scope === 'sub' ? $parent : null,
                packageId: $scope === 'pkg' ? $parent : null,
                offset: $offset,
            );

            return;
        }
        if (str_starts_with($data, 'more_reqs:')) {
            $this->listRequests($client, $chatId, (int) substr($data, 10));

            return;
        }
        if (str_starts_with($data, 'approve:')) {
            $ref = substr($data, 8);
            $this->runOwned($client, $ref, function (ServiceRequest $request) use ($chatId): void {
                $this->approveQuotation->handle($request);
                $this->safeSend($chatId, $this->tx(
                    "تمام، سجلنا موافقتك.\nحوّل المبلغ عبر شام كاش، وبعدين ابعت صورة الوصل أو PDF هون.",
                    "Your approval is recorded.\nTransfer the amount with Sham Cash, then send the receipt photo or PDF here.",
                ));
            });
            $session['step'] = 'receipt';
            $session['receipt_ref'] = $ref;
            unset($session['quote_ref']);
            $this->putSession($phone, $session);

            return;
        }
        if (str_starts_with($data, 'reject:')) {
            $ref = substr($data, 7);
            $session['step'] = 'reject_pick';
            $session['reject_ref'] = $ref;
            $this->putSession($phone, $session);
            $this->telegram->sendInlineKeyboard($chatId, $this->tx('سبب الرفض؟', 'Why are you rejecting it?'), [
                [
                    ['text' => $this->tx('السعر غالي', 'Price is high'), 'callback_data' => 'rjprice:'.$ref],
                    ['text' => $this->tx('تأخير بالرد', 'Reply was late'), 'callback_data' => 'rjdelay:'.$ref],
                ],
                [['text' => $this->tx('غير ذلك', 'Other'), 'callback_data' => 'rjother:'.$ref]],
            ]);

            return;
        }
        if (str_starts_with($data, 'rjprice:') || str_starts_with($data, 'rjdelay:')) {
            $reason = str_starts_with($data, 'rjprice:') ? 'السعر غالي' : 'تأخير بالرد';
            $ref = explode(':', $data, 2)[1] ?? '';
            $this->runOwned($client, $ref, function (ServiceRequest $request) use ($chatId, $reason): void {
                $this->rejectQuotation->handle($request, $reason);
                $this->sendMenu($chatId, $this->tx('تمام، رفضنا العرض. إذا بدك منرجعلك بعرض جديد.', 'The quotation was rejected. The team can send a new one.'));
            });
            $session['step'] = 'idle';
            unset($session['quote_ref'], $session['reject_ref']);
            $this->putSession($phone, $session);

            return;
        }
        if (str_starts_with($data, 'rjother:')) {
            $session['step'] = 'reject_reason';
            $session['reject_ref'] = explode(':', $data, 2)[1] ?? '';
            $this->putSession($phone, $session);
            $this->safeSend($chatId, $this->tx('إذا بدك سبب اكتبه، أو ابعت «تخطي».', 'Write a reason if you want, or send “skip”.'));

            return;
        }
        if (str_starts_with($data, 'okfile:')) {
            $this->approveFile($client, $chatId, substr($data, 7));

            return;
        }
        if (str_starts_with($data, 'revfile:') || str_starts_with($data, 'revision:')) {
            $payload = str_starts_with($data, 'revfile:') ? substr($data, 8) : substr($data, 9);
            $requestRef = $payload;
            $deliveryId = null;
            if (str_contains($payload, ':')) {
                [$requestRef, $deliveryId] = explode(':', $payload, 2);
            }
            $session['step'] = 'revision';
            $session['revision_ref'] = $requestRef;
            $session['revision_delivery_id'] = $deliveryId;
            $this->putSession($phone, $session);
            $this->safeSend($chatId, $this->tx('إذا بدك تحكي شو التعديل اكتبه، أو ابعت «تخطي».', 'Write the change if you want, or send “skip”.'));

            return;
        }
        if (str_starts_with($data, 'complete:')) {
            $this->runOwned($client, substr($data, 9), function (ServiceRequest $request) use ($chatId): void {
                abort_unless($request->status === RequestStatus::ReadyForReview, 422, 'Only ready-for-review requests can be completed.');
                $this->completeRequest->handle($request, 'client');
                $this->sendMenu($chatId, $this->tx('تمام، اعتمدنا التسليم. يعطيك العافية.', 'The delivery is accepted. Thank you.'));
            });

            return;
        }
        if (str_starts_with($data, 'receipt_hint:')) {
            $session['step'] = 'receipt';
            $session['receipt_ref'] = substr($data, 13);
            $this->putSession($phone, $session);
            $this->safeSend($chatId, $this->tx('ابعت وصل الدفع صورة أو PDF.', 'Send the payment receipt as a photo or PDF.'));

            return;
        }
        if (str_starts_with($data, 'renew:')) {
            $this->runOwned($client, substr($data, 6), function (ServiceRequest $request) use ($chatId): void {
                $this->renewSubscription->handle($request);
                $this->safeSend($chatId, $this->tx('منبعتلك فاتورة التجديد بالمبلغ المتفق عليه.', 'The renewal invoice is on its way, with the agreed amount.'));
            });

            return;
        }
        if (str_starts_with($data, 'norenew:')) {
            $this->runOwned($client, substr($data, 8), function (ServiceRequest $request) use ($chatId): void {
                $this->declineRenewal->handle($request);
                $this->sendMenu($chatId, $this->tx('تمام، ما رح نجدد هلق.', 'Recorded: you will not renew now.'));
            });

            return;
        }
        if (str_starts_with($data, 'reqack:')) {
            $this->runOwned($client, substr($data, 7), function (ServiceRequest $request) use ($chatId): void {
                $displayNumber = ResolveServiceRequest::displayNumber($request);
                $this->notifyEmployees->handle(
                    $request,
                    EmployeeProfession::Sales,
                    "✅ أكد الزبون اهتمامه بالطلب\n#{$displayNumber} — {$request->title}\n{$request->client?->name}",
                );
                $this->safeSend($chatId, $this->tx('تمام، خبرنا الفريق إنك مهتم.', 'The team was told you are interested.'));
            });

            return;
        }
        if (str_starts_with($data, 'reqcancel:')) {
            $this->runOwned($client, substr($data, 10), function (ServiceRequest $request) use ($chatId): void {
                if (! $request->status->canTransitionTo(RequestStatus::Cancelled)) {
                    throw ValidationException::withMessages(['status' => 'This request cannot be cancelled in its current state.']);
                }
                $updated = $this->transitions->transition($request, RequestStatus::Cancelled, 'client', 'Client cancelled via WhatsApp.');
                $fresh = $updated->fresh('client') ?? $updated;
                app(SyncClickUpFromStaff::class)->handle($fresh, ClickUpSyncEvent::Cancelled);
                $displayNumber = ResolveServiceRequest::displayNumber($fresh);
                $this->notifyEmployees->handle(
                    $fresh,
                    EmployeeProfession::Sales,
                    "❌ ألغى الزبون الطلب\n#{$displayNumber} — {$fresh->title}\n{$fresh->client?->name}",
                );
                $this->sendMenu($chatId, $this->tx('تمام، لغينا الطلب.', 'The request was cancelled.'));
            });
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function showCatalog(
        Client $client,
        string $chatId,
        string $phone,
        array $session,
        ?int $categoryId = null,
        ?int $subcategoryId = null,
        ?int $packageId = null,
        int $offset = 0,
    ): void {
        $session['catalog_stack'] = $session['catalog_stack'] ?? [];
        $this->putSession($phone, $session);

        $kind = 'categories';
        $items = [];
        if ($packageId) {
            $kind = 'periods';
            $items = [$this->pricingCatalog->packagePeriods($packageId)];
        } elseif ($subcategoryId) {
            $kind = 'packages';
            $items = $this->pricingCatalog->subcategoryPackages($subcategoryId);
        } elseif ($categoryId) {
            $items = $this->pricingCatalog->categoryChildren($categoryId);
            $kind = $items[0]['type'] ?? 'packages';
        } else {
            $items = $this->pricingCatalog->categories();
        }

        if ($kind === 'periods') {
            $first = $items[0] ?? [];
            $periods = array_values(array_filter(
                array_map('strval', $first['periods'] ?? []),
                fn (string $period): bool => BillingPeriod::isSubscription($period),
            ));
            if ($periods === []) {
                $this->createCatalog($client, $chatId, $phone, $session, (int) ($first['id'] ?? 0), null);

                return;
            }
            $rows = [];
            foreach ($periods as $period) {
                $rows[] = [[
                    'text' => BillingPeriod::label($period, $this->replyLang),
                    'callback_data' => 'per:'.(int) ($first['id'] ?? 0).':'.$period,
                ]];
            }
            $rows[] = [['text' => $this->tx('السابق', 'Back'), 'callback_data' => 'back']];
            $rows[] = [['text' => $this->tx('طلب يدوي', 'Manual request'), 'callback_data' => 'cman']];
            $this->telegram->sendInlineKeyboard(
                $chatId,
                $this->tx('قديش مدة الاشتراك لـ ', 'Choose the subscription length for ').((string) ($first['name'] ?? '')).':',
                $rows,
            );

            return;
        }

        $page = array_slice($items, $offset, PricingCatalog::PAGE_SIZE);
        $hasMore = ($offset + PricingCatalog::PAGE_SIZE) < count($items);
        $scope = 'root';
        $parent = 0;
        if ($categoryId) {
            $scope = 'cat';
            $parent = $categoryId;
        }
        if ($subcategoryId) {
            $scope = 'sub';
            $parent = $subcategoryId;
        }

        $rows = [];
        foreach ($page as $item) {
            $type = (string) ($item['type'] ?? $kind);
            $prefix = match (true) {
                $kind === 'packages' || $type === 'package' => 'pkg',
                $type === 'subcategory' => 'sub',
                default => 'cat',
            };
            $rows[] = [[
                'text' => (string) ($item['name'] ?? $item['id']),
                'callback_data' => $prefix.':'.(int) $item['id'],
            ]];
        }
        if ($hasMore) {
            $rows[] = [['text' => $this->tx('عرض المزيد', 'Show more'), 'callback_data' => "more:{$scope}:{$parent}:".($offset + PricingCatalog::PAGE_SIZE)]];
        }
        $rows[] = [['text' => $this->tx('السابق', 'Back'), 'callback_data' => 'back']];
        $rows[] = [['text' => $this->tx('طلب يدوي', 'Manual request'), 'callback_data' => 'cman']];

        $title = $this->tx('اختار القسم:', 'Choose a category:');
        if ($categoryId) {
            $title = $this->tx('اختار النوع أو الباقة:', 'Choose a subcategory or a package:');
        }
        if ($subcategoryId) {
            $title = $this->tx('اختار الباقة:', 'Choose a package:');
        }

        $this->telegram->sendInlineKeyboard($chatId, $title, $rows);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function catalogBack(Client $client, string $chatId, string $phone, array $session): void
    {
        if (($session['step'] ?? '') === 'title' || ($session['step'] ?? '') === 'body') {
            $this->resetCompose($session);
            $this->showCatalog($client, $chatId, $phone, $session);

            return;
        }

        $stack = $session['catalog_stack'] ?? [];
        if ($stack !== []) {
            array_pop($stack);
            $session['catalog_stack'] = $stack;
            $prev = $stack === [] ? null : $stack[array_key_last($stack)];
            if ($prev === null) {
                $this->showCatalog($client, $chatId, $phone, $session);

                return;
            }
            $kind = (string) ($prev['kind'] ?? '');
            $itemId = (int) ($prev['id'] ?? 0);
            if ($kind === 'cat') {
                $this->showCatalog($client, $chatId, $phone, $session, categoryId: $itemId);

                return;
            }
            if ($kind === 'sub') {
                $this->showCatalog($client, $chatId, $phone, $session, subcategoryId: $itemId);

                return;
            }
        }

        $this->resetCompose($session);
        $this->putSession($phone, $session);
        $this->sendMenu($chatId, $this->tx('رجعنالك عالقائمة.', 'Back to the main menu.'));
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function createCatalog(Client $client, string $chatId, string $phone, array $session, int $packageId, ?string $period): void
    {
        abort_unless($client->profileComplete(), 422, 'Complete your name, phone, and company first.');
        $this->safeSend($chatId, $this->tx(
            "عم نجهز طلبك والعرض.\nاستنى شوي وما تسكر المحادثة.",
            "Creating your request and waiting for the quotation…\nPlease wait a moment and keep this chat open.",
        ));

        $package = PricingPackage::query()->with('subcategory.category')->findOrFail($packageId);
        $serviceRequest = $this->createCatalogRequest->handle($client, $package, $period);
        $fresh = $serviceRequest->fresh() ?? $serviceRequest;
        $number = $fresh->number;
        $label = $this->statusText($fresh->status);
        if ($this->createCatalogRequest->quotationDelivered) {
            $session['step'] = 'quote';
            $session['quote_ref'] = ResolveServiceRequest::displayNumber($fresh);
            $session['catalog_stack'] = [];
            $this->putSession($phone, $session);

            return;
        }

        $session['step'] = 'idle';
        $this->putSession($phone, $session);
        $this->sendMenu($chatId, $this->tx(
            "فتحنا الطلب #{$number}.\nالحالة: {$label}.\nإذا ما وصلك العرض بهاللحظة، افتح طلباتي.",
            "Request #{$number} was created.\nStatus: {$label}.\nIf the quotation does not arrive in a moment, open My requests.",
        ));
    }

    private function listRequests(Client $client, string $chatId, int $offset = 0): void
    {
        $items = $client->requests()
            ->with('pricingPackage')
            ->latest('id')
            ->get()
            ->reject(fn (ServiceRequest $item) => $item->hiddenFromClient());

        if ($items->isEmpty()) {
            $this->sendMenu($chatId, $this->tx('لسا ما في طلبات.', 'There are no requests yet.'));

            return;
        }

        $pageSize = 9;
        $shown = $items->slice($offset, $pageSize);
        $lines = [$this->tx('ابعت رقم الطلب لحتى نفتحه:', 'Reply with the request number to open or edit it:')];
        $rows = [];
        foreach ($shown as $item) {
            $label = StatusLabel::request($item->status->value, $this->replyLang);
            $lines[] = "#{$item->number} — {$item->title} — {$label}";
            $rows[] = [[
                'text' => mb_substr("#{$item->number} {$label}", 0, 24),
                'callback_data' => 'open:'.$item->number,
            ]];
        }
        $next = $offset + $pageSize;
        if ($next < $items->count()) {
            $rows[] = [['text' => $this->tx('عرض الأقدم', 'Older'), 'callback_data' => 'more_reqs:'.$next]];
        }
        $rows[] = [['text' => $this->tx('القائمة', 'Menu'), 'callback_data' => 'menu:home']];
        $this->telegram->sendInlineKeyboard($chatId, implode("\n", $lines), $rows);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function askRequestHours(Client $client, string $chatId, string $phone, array &$session, int $offset = 0): void
    {
        $items = $client->requests()
            ->latest('id')
            ->get()
            ->reject(fn (ServiceRequest $item) => $item->hiddenFromClient())
            ->values();

        if ($items->isEmpty()) {
            $session['step'] = 'idle';
            unset($session['hours_numbers']);
            $this->putSession($phone, $session);
            $this->sendMenu($chatId, $this->tx('لسا ما في طلبات.', 'There are no requests yet.'));

            return;
        }

        $pageSize = 9;
        $shown = $items->slice($offset, $pageSize)->values();
        $numbers = $shown->map(fn (ServiceRequest $item): string => (string) $item->number)->all();
        $session['step'] = 'hours_pick';
        $session['hours_numbers'] = $numbers;
        $this->putSession($phone, $session);

        $lines = [$this->tx('أي طلب تقصد؟ أرسل رقمه:', 'Which request do you mean? Reply with its number:')];
        $rows = [];
        foreach ($shown as $item) {
            $lines[] = "#{$item->number} — {$item->title}";
            $rows[] = [[
                'text' => mb_substr("#{$item->number}", 0, 24),
                'callback_data' => 'hours:'.$item->number,
            ]];
        }
        $next = $offset + $pageSize;
        if ($next < $items->count()) {
            $rows[] = [['text' => $this->tx('عرض الأقدم', 'Older'), 'callback_data' => 'more_hours:'.$next]];
        }
        $this->telegram->sendInlineKeyboard($chatId, implode("\n", $lines), $rows);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function pickRequestHours(Client $client, string $chatId, string $phone, array &$session, string $text): void
    {
        $value = trim($text);
        $western = strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $numbers = is_array($session['hours_numbers'] ?? null) ? $session['hours_numbers'] : [];
        if (preg_match('/^(\d{1,2})$/', $western, $match) === 1) {
            $picked = $numbers[(int) $match[1] - 1] ?? null;
            if (is_string($picked) && $picked !== '') {
                $this->showTaskHours($client, $chatId, $phone, $session, $picked);

                return;
            }
        }

        $owned = $client->requests()->get()->first(
            fn (ServiceRequest $item): bool => ! $item->hiddenFromClient()
                && ($value === (string) $item->number || str_contains($value, (string) $item->number))
        );
        if ($owned instanceof ServiceRequest) {
            $this->showTaskHours($client, $chatId, $phone, $session, (string) $owned->number);

            return;
        }

        $this->safeSend($chatId, $this->tx('ابعت رقم الطلب من القائمة.', 'Reply with the request number from the list.'));
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function showTaskHours(Client $client, string $chatId, string $phone, array &$session, string $number): void
    {
        $item = $client->requests()->where('number', $number)->with('clickupTasks')->first();
        $session['step'] = 'idle';
        unset($session['hours_numbers']);
        $this->putSession($phone, $session);
        if ($item === null || $item->hiddenFromClient()) {
            $this->sendMenu($chatId, $this->tx('ما لقيت طلب بهاد الرقم.', 'There is no request with that number.'));

            return;
        }

        $lines = ["#{$item->number} — {$item->title}", $this->tx('ساعات العمل:', 'Work hours:')];
        $total = 0;
        $shown = 0;
        foreach ($item->clickupTasks as $task) {
            $hours = (int) ($task->planned_hours ?? 0);
            if ($hours < 1 || ! $task->task_type instanceof ClickUpTaskType) {
                continue;
            }
            $lines[] = $this->taskHourLabel($task->task_type).': '.$this->hourWord($hours);
            $total += $hours;
            $shown++;
        }
        if ($shown === 0) {
            $this->safeSend($chatId, $this->tx(
                "#{$item->number} — {$item->title}\nلم تُحدد ساعات العمل لهذا الطلب بعد.",
                "#{$item->number} — {$item->title}\nWork hours are not set for this request yet.",
            ));

            return;
        }

        $lines[] = $this->tx('المجموع: ', 'Total: ').$this->hourWord($total);
        $this->safeSend($chatId, implode("\n", $lines));
    }

    private function asksForRequestHours(string $text): bool
    {
        if (preg_match('/طلباتي|شو صار|وين طلب/u', $text) === 1
            || preg_match('/\b(my orders|my requests|order status)\b/i', $text) === 1) {
            return false;
        }

        return preg_match('/شو\s*(?:في الطلب|بالطلب|فيه الطلب|داخل الطلب)|محتوى الطلب|كم\s*ساعة|ساعات\s*(?:العمل|المهام)|الوقت\s*المحتاج|تاسك/u', $text) === 1
            || preg_match('/\b(what(?:\'s| is) in my (?:request|order)|how many hours|hours needed|task hours)\b/i', $text) === 1;
    }

    private function taskHourLabel(ClickUpTaskType $type): string
    {
        return match ($type) {
            ClickUpTaskType::Sales => $this->tx('المبيعات', 'Sales'),
            ClickUpTaskType::Design => $this->tx('التصميم', 'Design'),
            ClickUpTaskType::Content => $this->tx('المحتوى', 'Content'),
            ClickUpTaskType::Programming => $this->tx('البرمجة', 'Programming'),
            ClickUpTaskType::Photography => $this->tx('التصوير', 'Photography'),
            ClickUpTaskType::Revision => $this->tx('التعديل', 'Revision'),
        };
    }

    private function hourWord(int $hours): string
    {
        if ($this->replyLang === 'en') {
            return $hours === 1 ? '1 hour' : $hours.' hours';
        }
        if ($hours === 1) {
            return 'ساعة';
        }
        if ($hours === 2) {
            return 'ساعتان';
        }
        if ($hours >= 3 && $hours <= 10) {
            return $hours.' ساعات';
        }

        return $hours.' ساعة';
    }

    private function showRequest(Client $client, string $chatId, string $number): void
    {
        $item = $client->requests()->where('number', $number)->first();
        if ($item === null || $item->hiddenFromClient()) {
            $this->sendMenu($chatId, $this->tx('ما لقيت طلب بهاد الرقم.', 'There is no request with that number.'));

            return;
        }

        $label = StatusLabel::request($item->status->value, $this->replyLang);
        $package = ($this->replyLang === 'en' ? $item->pricingPackage?->name_en : null)
            ?: $item->pricingPackage?->name_ar
            ?: $item->pricingPackage?->name_en
            ?: $this->tx('طلب يدوي', 'Manual request');
        $lines = [
            "#{$item->number} — {$item->title}",
            $this->tx('الحالة: ', 'Status: ').$label,
            $this->tx('الباقة: ', 'Package: ').$package,
        ];
        if (filled($item->description)) {
            $lines[] = $this->tx('الوصف: ', 'Description: ').mb_substr((string) $item->description, 0, 280);
        }
        if ($item->amount_paid !== null || $item->amount_remaining !== null) {
            $paid = $item->amount_paid ?: 0;
            $left = $item->amount_remaining ?: 0;
            $lines[] = $this->tx(
                'المدفوع: '.$paid.' USD — المتبقي: '.$left.' USD',
                'Paid: '.$paid.' USD — remaining: '.$left.' USD',
            );
        }

        $ref = ResolveServiceRequest::displayNumber($item);
        $rows = [];
        if ($item->status->allowsClientEdit()) {
            $rows[] = [['text' => $this->tx('تعديل البيانات', 'Edit details'), 'callback_data' => 'edit:'.$item->number]];
        }
        if ($item->allowsClientRevision()) {
            $rows[] = [['text' => $this->tx('طلب تعديل', 'Request a revision'), 'callback_data' => 'revision:'.$ref]];
        }
        if ($item->status === RequestStatus::ReadyForReview) {
            $rows[] = [['text' => $this->tx('اعتماد التسليم', 'Accept delivery'), 'callback_data' => 'complete:'.$ref]];
        }
        if ($item->receipt_reupload_required || $item->acceptsReceiptUpload()) {
            $lines[] = $this->tx('ابعت وصل الدفع صورة أو PDF', 'Send the payment receipt as a photo or PDF');
            $rows[] = [['text' => $this->tx('رفع وصل الدفع', 'Upload receipt'), 'callback_data' => 'receipt_hint:'.$ref]];
        }
        if ($item->canRenew()) {
            $rows[] = [
                ['text' => $this->tx('تجديد', 'Renew'), 'callback_data' => 'renew:'.$item->number],
                ['text' => $this->tx('لن أجدد', 'I will not renew'), 'callback_data' => 'norenew:'.$item->number],
            ];
        }
        $rows[] = [['text' => $this->tx('طلباتي', 'My requests'), 'callback_data' => 'menu:mine']];
        $this->telegram->sendInlineKeyboard($chatId, implode("\n", $lines), $rows);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function startEdit(Client $client, string $chatId, string $phone, array &$session): void
    {
        $editable = $client->requests()
            ->latest('id')
            ->get()
            ->filter(fn (ServiceRequest $item) => $item->status->allowsClientEdit() && ! $item->hiddenFromClient())
            ->values();
        if ($editable->isEmpty()) {
            $this->sendMenu($chatId, $this->tx(
                "التعديل متاح قبل صدور عرض السعر.\nافتح طلباتي لرؤية الحالة، أو اطلب تعديلاً على التسليم بعد بدء التنفيذ.",
                "Editing is available before the quotation is sent.\nOpen My requests to see the status, or ask for a delivery revision after work starts.",
            ));

            return;
        }
        if ($editable->count() === 1) {
            $this->beginEdit($client, $chatId, $phone, $session, (string) $editable->first()->number);

            return;
        }
        $this->listRequests($client, $chatId);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function beginEdit(Client $client, string $chatId, string $phone, array &$session, string $number): void
    {
        $item = $client->requests()->where('number', $number)->first();
        if ($item === null || ! $item->status->allowsClientEdit()) {
            $this->sendMenu($chatId, $this->tx('ما عاد فينا نعدل بيانات هاد الطلب.', 'This request can no longer be edited.'));

            return;
        }
        $session['step'] = 'edit_title';
        $session['edit_number'] = $item->number;
        $this->putSession($phone, $session);
        $this->safeSend($chatId, $this->tx(
            "تعديل #{$item->number}.\nالعنوان الحالي: {$item->title}\nأرسل العنوان الجديد.",
            "Editing #{$item->number}.\nCurrent title: {$item->title}\nSend the new title.",
        ));
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function captureEditTitle(string $chatId, string $phone, array &$session, string $text): void
    {
        $title = trim($text);
        if ($title === '' || mb_strlen($title) > 255) {
            $this->safeSend($chatId, $this->tx('اكتب عنوان أوضح، وبسطر واحد.', 'Send a clear title, up to 255 characters.'));

            return;
        }
        $session['edit_title'] = $title;
        $session['step'] = 'edit_body';
        $this->putSession($phone, $session);
        $this->safeSend($chatId, $this->tx('اكتب الوصف الجديد بجملة واضحة.', 'Send the new description in one clear sentence.'));
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function captureEditBody(Client $client, string $chatId, string $phone, array &$session, string $text): void
    {
        $description = trim($text);
        if (mb_strlen($description) < 15) {
            $this->safeSend($chatId, $this->tx('اكتب جملة أوضح عن التعديل.', 'Send one clearer sentence describing the change.'));

            return;
        }
        $item = $client->requests()->where('number', (string) ($session['edit_number'] ?? ''))->first();
        if ($item === null || ! $item->status->allowsClientEdit()) {
            $session['step'] = 'idle';
            unset($session['edit_number'], $session['edit_title']);
            $this->putSession($phone, $session);
            $this->sendMenu($chatId, $this->tx('ما عاد فينا نعدل بيانات هاد الطلب.', 'This request can no longer be edited.'));

            return;
        }
        $item->forceFill([
            'title' => (string) $session['edit_title'],
            'description' => mb_substr($description, 0, 10000),
        ])->save();
        $session['step'] = 'idle';
        unset($session['edit_number'], $session['edit_title']);
        $this->putSession($phone, $session);
        $this->showRequest($client, $chatId, (string) $item->number);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function routeSentence(Client $client, string $chatId, string $phone, array $session, string $text): bool
    {
        $text = trim($text);
        if ($text === '' || ClientProfileValue::isKeyboardLabel($text)) {
            return false;
        }

        $step = (string) ($session['step'] ?? 'idle');
        $target = $this->profileTarget($text);
        if ($target !== null) {
            $this->openProfileTarget($client, $chatId, $phone, $session, $target);

            return true;
        }

        $intent = $this->operationIntent($text);
        $word = $this->quoteWord($text);
        if ($intent === null && $word === 'approve' && ($step === 'quote' || $step === 'suggest' || $this->openQuotation($client) !== null)) {
            $intent = 'approve';
        }
        if ($intent === null && $word === 'reject' && in_array($step, ['quote', 'suggest'], true)) {
            $intent = 'reject';
        }
        if ($this->wantsBiggerPackage($text) && ($step === 'quote' || $this->openQuotation($client) !== null)) {
            $open = $this->openQuotation($client);
            if ($open !== null) {
                $this->offerLargerPackage($client, $chatId, $phone, $session, $open);

                return true;
            }
        }
        if ($intent === null && $step !== 'quote' && $this->wantsPackage($text)) {
            $open = $this->openQuotation($client);
            if ($open !== null) {
                $session['quote_ref'] = ResolveServiceRequest::displayNumber($open);
                $this->safeSend($chatId, $this->tx(
                    'عندك عرض سعر لم يُحسم بعد. أكمله بالموافقة أو الرفض قبل طلب جديد.',
                    'A quotation is still waiting. Approve or reject it before starting a new request.',
                ));
                $this->showQuoteDecision($chatId, $phone, $session);

                return true;
            }
            if ($this->suggestPackage($client, $chatId, $phone, $session, $text)) {
                return true;
            }
        }
        if ($intent === null && mb_strlen($text) >= 8) {
            $classified = app(GeminiService::class)->classifyClientIntent($text, $step === '' ? 'idle' : $step);
            $intent = $classified === 'none' ? null : $classified;
        }
        if ($intent === null) {
            return false;
        }

        if ($intent === 'new' && ($step === 'quote' || $this->openQuotation($client) !== null)) {
            $open = $this->openQuotation($client);
            if ($open !== null) {
                $session['quote_ref'] = ResolveServiceRequest::displayNumber($open);
            }
            $this->safeSend($chatId, $this->tx(
                'عندك عرض سعر لم يُحسم بعد. أكمله بالموافقة أو الرفض قبل طلب جديد.',
                'A quotation is still waiting. Approve or reject it before starting a new request.',
            ));
            $this->showQuoteDecision($chatId, $phone, $session);

            return true;
        }
        if ($intent === 'approve' && $step === 'suggest') {
            $this->continueSuggest($client, $chatId, $phone, $session, '1');

            return true;
        }
        if ($intent === 'reject' && $step === 'suggest') {
            $this->continueSuggest($client, $chatId, $phone, $session, '2');

            return true;
        }
        if ($intent === 'approve' && ($step === 'quote' || $this->openQuotation($client) !== null)) {
            $open = $this->openQuotation($client);
            if ($open !== null && ! filled($session['quote_ref'] ?? null)) {
                $session['quote_ref'] = ResolveServiceRequest::displayNumber($open);
            }
            $this->continueQuote($client, $chatId, $phone, $session, '1');

            return true;
        }
        if ($intent === 'reject' && $step === 'quote') {
            $this->continueQuote($client, $chatId, $phone, $session, '2');

            return true;
        }
        if ($intent === 'hours') {
            $this->askRequestHours($client, $chatId, $phone, $session);

            return true;
        }
        if ($intent === 'renew') {
            $this->startRenewal($client, $chatId);

            return true;
        }
        if ($intent === 'norenew') {
            $this->declineRenewalFromChat($client, $chatId);

            return true;
        }
        if ($intent === 'photo') {
            $this->offerPhotography($client, $chatId, $phone, $session);

            return true;
        }
        if ($intent === 'requests') {
            $this->listRequests($client, $chatId);

            return true;
        }
        if ($intent === 'edit') {
            $this->startEdit($client, $chatId, $phone, $session);

            return true;
        }
        if ($intent === 'profile') {
            $this->showProfile($client, $chatId, $phone, $session);

            return true;
        }
        if ($intent === 'new') {
            if ($this->gateProfile($client, $chatId, $session)) {
                $this->showCatalog($client, $chatId, $phone, $session);
            }

            return true;
        }
        if ($intent === 'help') {
            $this->sendMenu($chatId, $this->tx('احكي معنا عالرقم: ', 'Call us: ').self::SUPPORT_PHONE);

            return true;
        }
        if ($intent === 'ask') {
            $this->answerAsk($chatId, $phone, $session, $text);

            return true;
        }

        return false;
    }

    private function operationIntent(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if ($this->asksForRequestHours($text)) {
            return 'hours';
        }
        if (preg_match('/لن\s*أجدد|لن\s*اجدد|ما\s*(?:بدي|أبي|ابغى)\s*جدد/u', $text) === 1
            || preg_match('/\b(will not renew|not renewing)\b/i', $text) === 1) {
            return 'norenew';
        }
        if (preg_match('/تجديد|أجدد|اجدد|جدد\s*ال?اشتراك/u', $text) === 1
            || preg_match('/\brenew\b/i', $text) === 1) {
            return 'renew';
        }
        if (preg_match('/موعد\s*تصوير|حجز\s*تصوير|بدي\s*أصو|أبي\s*أصو|ابغى\s*أصو/u', $text) === 1
            || preg_match('/\b(photography|photo shoot|book a shoot)\b/i', $text) === 1) {
            return 'photo';
        }
        if (preg_match('/طلباتي|وين طلب|شو صار بطلب/u', $text) === 1
            || preg_match('/\b(my orders|my requests|order status)\b/i', $text) === 1) {
            return 'requests';
        }
        if (preg_match('/تعديل|عدّل|(?:^|\s)عدل(?:\s|$)/u', $text) === 1
            || (preg_match('/\b(edit|revise)\b/i', $text) === 1 && preg_match('/\b(order|request|title|description)\b/i', $text) === 1)) {
            return 'edit';
        }
        if (preg_match('/طلب جديد|أبي أطلب|بدي أطلب/u', $text) === 1
            || preg_match('/\b(new request|new order)\b/i', $text) === 1) {
            return 'new';
        }
        if (preg_match('/(?:^|\s)الدعم(?:\s|$)/u', $text) === 1
            || preg_match('/\b(support|help)\b/i', $text) === 1) {
            return 'help';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function captureTitle(Client $client, string $chatId, string $phone, array $session, ?string $text): void
    {
        $value = trim((string) $text);
        if ($value === '') {
            $this->safeSend($chatId, $this->tx('شو عنوان الطلب؟', 'What is the request title?'));

            return;
        }
        $session['title'] = $value;
        $session['step'] = 'body';
        $session['attachments'] = [];
        $session['description'] = '';
        $this->putSession($phone, $session);
        $this->telegram->sendInlineKeyboard(
            $chatId,
            $this->tx(
                "احكيلنا شو بدك.\nفيك تبعت نص أو صور أو ملفات (JPG, PNG, PDF).\nلما تخلص اكتب «تم الإرسال».",
                "Describe what you need.\nYou can send text, photos, or files (JPG, PNG, PDF).\nWhen you finish, send “done”.",
            ),
            [[['text' => $this->tx('السابق', 'Back'), 'callback_data' => 'back']]],
        );
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array{file_name: string, file_base64: string, mime_type: string}|null  $media
     */
    private function captureBody(Client $client, string $chatId, string $phone, array $session, ?string $text, ?array $media): void
    {
        $trimmed = trim((string) $text);
        if (in_array(mb_strtolower($trimmed), ['تم الإرسال', '✅ تم الإرسال', 'تم', 'done', 'sent', 'submit'], true)) {
            $this->submitManual($client, $chatId, $phone, $session);

            return;
        }

        if ($media !== null) {
            $attachments = $session['attachments'] ?? [];
            if (count($attachments) >= 5) {
                $this->safeSend($chatId, $this->tx('الحد الأقصى 5 مرفقات.', 'The maximum is 5 attachments.'));

                return;
            }
            $attachments[] = $media;
            $session['attachments'] = $attachments;
            $this->putSession($phone, $session);
            $count = count($attachments);
            $this->safeSend($chatId, $this->tx(
                'وصل المرفق ('.$count.'/5). اكتب الوصف أو ابعت «تم الإرسال».',
                'Attachment saved ('.$count.'/5). Send a description or reply “done”.',
            ));

            return;
        }

        if ($trimmed !== '') {
            $session['description'] = $trimmed;
            $this->putSession($phone, $session);
            $this->safeSend($chatId, $this->tx(
                'سجلنا الوصف. إذا في ملفات ابعتها، وبعدين اكتب «تم الإرسال».',
                'Description saved. Send attachments if you have any, then reply “done”.',
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function submitManual(Client $client, string $chatId, string $phone, array $session): void
    {
        abort_unless($client->profileComplete(), 422, 'Complete your name, phone, and company first.');
        $title = trim((string) ($session['title'] ?? ''));
        $description = trim((string) ($session['description'] ?? ''));
        $attachments = $session['attachments'] ?? [];
        if ($title === '' || ($description === '' && $attachments === [])) {
            $this->safeSend($chatId, $this->tx(
                'العنوان ووصف أو مرفق واحد على الأقل مطلوبان.',
                'A title and either a description or one attachment are required.',
            ));

            return;
        }

        $serviceRequest = $this->submitServiceRequest->handle($client, [
            'title' => $title,
            'description' => $description !== '' ? $description : $this->tx('انظر المرفقات.', 'See the attachments.'),
            'source' => $client->requestSource(),
            'attachments' => $attachments,
        ]);
        $this->resetCompose($session);
        $this->putSession($phone, $session);
        $fresh = $serviceRequest->fresh() ?? $serviceRequest;
        $manualLabel = $this->statusText($fresh->status);
        $this->sendMenu($chatId, $this->tx(
            "فتحنا الطلب #{$fresh->number}.\nالحالة: {$manualLabel}.",
            "Request #{$fresh->number} was created.\nStatus: {$manualLabel}.",
        ));
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function finishReject(Client $client, string $chatId, string $phone, array $session, ?string $text): void
    {
        $reason = trim((string) $text);
        if ($reason === '') {
            $this->safeSend($chatId, $this->tx('إذا بدك سبب اكتبه، أو ابعت «تخطي».', 'Write a reason if you want, or send “skip”.'));

            return;
        }
        if ($this->feedbackSkipped($reason)) {
            $reason = '';
        }
        $this->runOwned($client, (string) ($session['reject_ref'] ?? ''), function (ServiceRequest $request) use ($chatId, $reason): void {
            $this->rejectQuotation->handle($request, $reason);
            $this->sendMenu($chatId, $this->tx('تمام، رفضنا العرض.', 'The quotation was rejected.'));
        });
        $session['step'] = 'idle';
        unset($session['reject_ref']);
        $this->putSession($phone, $session);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function finishRevision(Client $client, string $chatId, string $phone, array $session, ?string $text): void
    {
        $reason = trim((string) $text);
        if ($reason === '') {
            $this->safeSend($chatId, $this->tx('إذا بدك تحكي شو التعديل اكتبه، أو ابعت «تخطي».', 'Write the change if you want, or send “skip”.'));

            return;
        }
        if ($this->feedbackSkipped($reason)) {
            $reason = '';
        }
        $this->runOwned($client, (string) ($session['revision_ref'] ?? ''), function (ServiceRequest $request) use ($session, $chatId, $reason): void {
            $delivery = null;
            if (filled($session['revision_delivery_id'] ?? null)) {
                $delivery = DriveDelivery::query()
                    ->where('id', $session['revision_delivery_id'])
                    ->where('request_id', $request->id)
                    ->first();
            }
            $this->requestRevision->handle($request, $reason, $delivery);
            $this->sendMenu($chatId, $this->tx('وصلنا التعديل، ومنشتغل عليه.', 'The revision request was sent to the team.'));
        });
        $session['step'] = 'idle';
        unset($session['revision_ref'], $session['revision_delivery_id']);
        $this->putSession($phone, $session);
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array{file_name: string, file_base64: string, mime_type: string}  $media
     */
    private function storeReceipt(Client $client, string $chatId, string $phone, array $session, array $media): void
    {
        $this->runOwned($client, (string) ($session['receipt_ref'] ?? ''), function (ServiceRequest $request) use ($media, $chatId): void {
            abort_unless($request->acceptsReceiptUpload(), 422, 'Receipt upload is only allowed while awaiting payment.');
            $binary = base64_decode($media['file_base64'], true);
            abort_if($binary === false, 422, 'Invalid file payload.');
            $mime = $media['mime_type'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
            abort_unless(in_array($mime, $allowed, true), 422, 'Unsupported file type.');
            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'pdf',
            };
            $path = "receipts/{$request->number}-".now()->format('YmdHis').".{$extension}";
            Storage::disk('local')->put($path, $binary);
            $receiptFile = RequestFile::query()->create([
                'request_id' => $request->id,
                'kind' => 'payment_receipt',
                'original_name' => $media['file_name'],
                'path' => $path,
            ]);
            $request->forceFill([
                'receipt_reupload_required' => false,
                'receipt_reupload_reason' => null,
            ])->save();
            $request->load('client');
            $displayNumber = ResolveServiceRequest::displayNumber($request);
            $this->notifyPaymentStage->handle(
                $request,
                $receiptFile,
                "📎 رفع الزبون وصل دفع\n#{$displayNumber} — {$request->title}\n{$request->client?->name}",
            );
            $this->provisionSalesClickUpTask->appendReceipt($request, $receiptFile);
            $this->sendMenu($chatId, $this->tx('وصلنا وصل الدفع، ومنراجعه.', 'The payment receipt was received. The team will review it.'));
        });
        $session['step'] = 'idle';
        unset($session['receipt_ref']);
        $this->putSession($phone, $session);
    }

    private function approveFile(Client $client, string $chatId, string $payload): void
    {
        if (! str_contains($payload, ':')) {
            $this->safeSend($chatId, $this->tx('تعذر قراءة ملف الموافقة.', 'The approval could not be read.'));

            return;
        }
        [$ref, $deliveryId] = explode(':', $payload, 2);
        $this->runOwned($client, $ref, function (ServiceRequest $request) use ($deliveryId, $chatId): void {
            $delivery = DriveDelivery::query()
                ->where('id', $deliveryId)
                ->where('request_id', $request->id)
                ->firstOrFail();
            $this->approveDriveDelivery->handle($request, $delivery);
            $this->safeSend($chatId, $this->tx('تمام، وافقت عالملف.', 'Your approval of the file is recorded.'));
        });
    }

    /**
     * @param  callable(ServiceRequest): void  $callback
     */
    private function runOwned(Client $client, string $ref, callable $callback): void
    {
        $request = $this->resolveServiceRequest->byReference($ref);
        abort_unless($request->client_id === $client->id, 403);
        $callback($request->load('client'));
    }

    private function pendingReceiptRequest(Client $client): ?ServiceRequest
    {
        return $client->requests()
            ->latest('id')
            ->get()
            ->first(fn (ServiceRequest $request): bool => $request->acceptsReceiptUpload() || $request->receipt_reupload_required);
    }

    private function pushCompletedClientToOdoo(Client $client): void
    {
        if (! $client->readyForOdoo()) {
            return;
        }

        $fresh = $this->pushClientToOdoo->handle($client->fresh() ?? $client);
        $this->pushClientLeadToOdoo->handle(
            $fresh,
            writeExisting: filled($fresh->odoo_lead_id),
            classifyIndustry: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function session(string $phone): array
    {
        $session = Cache::get($this->sessionKey($phone), []);

        return is_array($session) ? $session : [];
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function putSession(string $phone, array $session): void
    {
        Cache::put($this->sessionKey($phone), $session, self::SESSION_TTL_SECONDS);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function resetCompose(array &$session): void
    {
        $session['step'] = 'idle';
        $session['catalog_stack'] = [];
        unset(
            $session['title'],
            $session['description'],
            $session['attachments'],
            $session['reject_ref'],
            $session['profile_field'],
            $session['revision_ref'],
            $session['revision_delivery_id'],
            $session['receipt_ref'],
        );
    }

    private function sessionKey(string $phone): string
    {
        return 'hoc:wa-session:'.$phone;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function callbackId(array $message): ?string
    {
        if (($message['type'] ?? '') !== 'interactive') {
            return null;
        }
        $interactive = $message['interactive'] ?? [];
        if (! is_array($interactive)) {
            return null;
        }
        $id = $interactive['button_reply']['id'] ?? $interactive['list_reply']['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function messageText(array $message): ?string
    {
        $body = $message['text']['body'] ?? $message['button']['text'] ?? null;

        return is_string($body) ? trim($body) : null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{file_name: string, file_base64: string, mime_type: string}|null
     */
    private function messageMedia(array $message): ?array
    {
        $type = (string) ($message['type'] ?? '');
        $node = match ($type) {
            'image' => $message['image'] ?? null,
            'document' => $message['document'] ?? null,
            default => null,
        };
        if (! is_array($node)) {
            return null;
        }

        $inline = $node['file_base64'] ?? null;
        if (is_string($inline) && $inline !== '') {
            $mime = (string) ($node['mime_type'] ?? 'application/octet-stream');

            return [
                'file_name' => (string) ($node['filename'] ?? ($type.'.'.(str_contains($mime, 'pdf') ? 'pdf' : 'jpg'))),
                'file_base64' => $inline,
                'mime_type' => $mime,
            ];
        }

        if (! filled($node['id'] ?? null)) {
            return null;
        }

        try {
            $downloaded = $this->whatsApp->downloadMedia((string) $node['id']);
        } catch (Throwable $exception) {
            Log::warning('WhatsApp media download failed.', ['error' => $exception->getMessage()]);

            return null;
        }

        $mime = (string) ($node['mime_type'] ?? $downloaded['mime']);
        $name = (string) ($node['filename'] ?? ($type.'.'.(str_contains($mime, 'pdf') ? 'pdf' : 'jpg')));

        return [
            'file_name' => $name,
            'file_base64' => base64_encode($downloaded['binary']),
            'mime_type' => $mime,
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{file_base64: string, mime_type: string}|null
     */
    private function voiceNote(array $message): ?array
    {
        if (($message['type'] ?? '') !== 'audio' || ! is_array($message['audio'] ?? null)) {
            return null;
        }
        $inline = $message['audio']['file_base64'] ?? null;
        if (! is_string($inline) || $inline === '') {
            return null;
        }

        return [
            'file_base64' => $inline,
            'mime_type' => (string) ($message['audio']['mime_type'] ?? 'audio/ogg'),
        ];
    }

    private function startRenewal(Client $client, string $chatId): void
    {
        $items = $client->requests()->latest('id')->get()
            ->filter(fn (ServiceRequest $item): bool => $item->canRenew() && ! $item->hiddenFromClient())
            ->values();
        if ($items->isEmpty()) {
            $this->sendMenu($chatId, $this->tx(
                'التجديد بيصير بآخر 8 أيام من الاشتراك.',
                'Renewal opens during the last 8 days of the subscription.',
            ));

            return;
        }
        if ($items->count() === 1) {
            $this->renewSubscription->handle($items->first());
            $this->safeSend($chatId, $this->tx(
                'منبعتلك فاتورة التجديد بالمبلغ المتفق عليه.',
                'The renewal invoice is on its way, with the agreed amount.',
            ));

            return;
        }
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [['text' => '#'.$item->number, 'callback_data' => 'renew:'.$item->number]];
        }
        $this->telegram->sendInlineKeyboard($chatId, $this->tx('أي اشتراك بدك تجدده؟', 'Which subscription should be renewed?'), $rows);
    }

    private function declineRenewalFromChat(Client $client, string $chatId): void
    {
        $items = $client->requests()->latest('id')->get()
            ->filter(fn (ServiceRequest $item): bool => $item->canRenew() && ! $item->hiddenFromClient())
            ->values();
        if ($items->isEmpty()) {
            $this->sendMenu($chatId, $this->tx('ما في اشتراك جاهز للتجديد هلق.', 'No subscription is waiting for renewal.'));

            return;
        }
        if ($items->count() === 1) {
            $this->declineRenewal->handle($items->first());
            $this->sendMenu($chatId, $this->tx('تمام، ما رح نجدد هلق.', 'Recorded: you will not renew now.'));

            return;
        }
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [['text' => '#'.$item->number, 'callback_data' => 'norenew:'.$item->number]];
        }
        $this->telegram->sendInlineKeyboard($chatId, $this->tx('أي اشتراك ما بدك تجدده؟', 'Which subscription will you not renew?'), $rows);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function offerPhotography(Client $client, string $chatId, string $phone, array &$session): void
    {
        $request = $this->photographyRequest($client);
        if ($request === null) {
            $this->sendMenu($chatId, $this->tx(
                'حجز التصوير للباقات يلي فيها تصوير واشتراكها شغال.',
                'Photography booking is for an active package that includes photography.',
            ));

            return;
        }
        $used = PhotographyBooking::query()
            ->where('request_id', $request->id)
            ->whereIn('status', ['pending_staff', 'needs_client', 'confirmed'])
            ->count();
        $left = $this->shootAllowance($request) - $used;
        if ($left < 1) {
            $this->sendMenu($chatId, $this->tx(
                'خلصت مواعيد التصوير بباقتك.',
                'The photography times in your package are used.',
            ));

            return;
        }
        $days = app(BookPhotographySlot::class)->bookableDays();
        if ($days === []) {
            $this->sendMenu($chatId, $this->tx('ما في يوم فاضي للتصوير هالفترة.', 'No photography day is free right now.'));

            return;
        }
        $name = $request->pricingPackage?->name_ar ?: $request->title;
        $word = $left === 1 ? 'موعد' : 'مواعيد';
        $lines = [$this->tx(
            "باقة {$name}: باقيلك {$left} {$word}.\nاختار اليوم:",
            "Package {$name}: {$left} photography ".($left === 1 ? 'time' : 'times')." left.\nPick a day:",
        )];
        $rows = [];
        foreach ($days as $index => $day) {
            $lines[] = ($index + 1).'. '.$day['label'];
            $rows[] = [['text' => $day['label'], 'callback_data' => 'photo_day:'.$index]];
        }
        $session['step'] = 'photo_day';
        $session['photo_request'] = $request->number;
        $session['photo_days'] = array_column($days, 'date');
        unset($session['photo_slots']);
        $this->putSession($phone, $session);
        $this->telegram->sendInlineKeyboard($chatId, implode("\n", $lines), $rows);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function pickPhotoDay(Client $client, string $chatId, string $phone, array &$session, int $choice): void
    {
        $days = is_array($session['photo_days'] ?? null) ? array_values($session['photo_days']) : [];
        $date = $days[$choice - 1] ?? null;
        if (! is_string($date)) {
            $this->offerPhotography($client, $chatId, $phone, $session);

            return;
        }
        $slots = app(BookPhotographySlot::class)->timesOn(\Illuminate\Support\Carbon::parse($date, 'Asia/Damascus'));
        if ($slots === []) {
            $this->safeSend($chatId, $this->tx('هاد اليوم مليان، اختار يوم تاني.', 'That day is full. Pick another day.'));
            $this->offerPhotography($client, $chatId, $phone, $session);

            return;
        }
        $lines = [$this->tx('اختار الوقت:', 'Pick a time:')];
        $rows = [];
        foreach ($slots as $index => $slot) {
            $lines[] = ($index + 1).'. '.$slot['label'];
            $rows[] = [['text' => $slot['label'], 'callback_data' => 'photo_time:'.$index]];
        }
        $session['step'] = 'photo_time';
        $session['photo_slots'] = array_column($slots, 'starts_at');
        $this->putSession($phone, $session);
        $this->telegram->sendInlineKeyboard($chatId, implode("\n", $lines), array_slice($rows, 0, 10));
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function bookPhotoSlot(Client $client, string $chatId, string $phone, array &$session, int $choice): void
    {
        $slots = is_array($session['photo_slots'] ?? null) ? array_values($session['photo_slots']) : [];
        $startsAt = $slots[$choice - 1] ?? null;
        $request = $client->requests()->where('number', (string) ($session['photo_request'] ?? ''))->first();
        if (! is_string($startsAt) || ! $request instanceof ServiceRequest) {
            $session['step'] = 'idle';
            $this->putSession($phone, $session);
            $this->offerPhotography($client, $chatId, $phone, $session);

            return;
        }
        try {
            $booking = app(BookPhotographySlot::class)->hold($request, $startsAt);
            $reply = app(BookPhotographySlot::class)->clientMessage($booking);
        } catch (ValidationException $exception) {
            $reply = collect($exception->errors())->flatten()->first() ?: $this->tx('ما قدرنا نحجز هاد الوقت.', 'That time could not be booked.');
        }
        $session['step'] = 'idle';
        unset($session['photo_request'], $session['photo_slots']);
        $this->putSession($phone, $session);
        $this->safeSend($chatId, (string) $reply);
    }

    private function photographyRequest(Client $client): ?ServiceRequest
    {
        return $client->requests()
            ->with('pricingPackage')
            ->where('allows_renewal', true)
            ->where(function ($query): void {
                $query->where('subscription_ends_at', '>', now())
                    ->orWhereIn('status', [
                        RequestStatus::PaymentConfirmed->value,
                        RequestStatus::InProgress->value,
                        RequestStatus::ReadyForReview->value,
                        RequestStatus::RevisionRequested->value,
                    ]);
            })
            ->latest('id')
            ->get()
            ->first(function (ServiceRequest $item): bool {
                return ! $item->hiddenFromClient()
                    && ! $item->renewalDeclined()
                    && $this->shootAllowance($item) > 0;
            });
    }

    private function shootAllowance(ServiceRequest $request): int
    {
        $hours = 0;
        foreach (WorkLines::fromRequest($request) as $line) {
            if ($line['department'] === 'photography') {
                $hours += $line['hours'];
            }
        }
        if ($hours >= 1) {
            return max(1, intdiv($hours, BookPhotographySlot::SHOOT_HOURS));
        }
        $features = json_encode($request->pricingPackage?->features ?? '', JSON_UNESCAPED_UNICODE) ?: '';

        return str_contains($features, 'تصوير') ? 1 : 0;
    }

    /**
     * @param  list<string>  $labels
     */
    private function isNav(?string $text, array $labels): bool
    {
        if ($text === null) {
            return false;
        }
        $normalized = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $text));
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $normalized));

        foreach ($labels as $label) {
            $needle = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $label));
            if ($text === $label || $normalized === $needle) {
                return true;
            }
        }

        return false;
    }

    private function sendMenu(string $chatId, string $text): void
    {
        $this->telegram->sendInlineKeyboard($chatId, $text, [
            [['text' => $this->tx('طلب جديد', 'New request'), 'callback_data' => 'menu:new']],
            [['text' => $this->tx('طلباتي', 'My requests'), 'callback_data' => 'menu:mine']],
            [['text' => $this->tx('حجز تصوير', 'Photo booking'), 'callback_data' => 'menu:photo']],
            [['text' => $this->tx('تجديد الاشتراك', 'Renew subscription'), 'callback_data' => 'menu:renew']],
            [['text' => $this->tx('بياناتي', 'My details'), 'callback_data' => 'menu:profile']],
            [['text' => $this->tx('استفسار', 'Inquiry'), 'callback_data' => 'menu:ask']],
            [['text' => $this->tx('الدعم', 'Support'), 'callback_data' => 'menu:help']],
        ]);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function profileTarget(?string $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '' || mb_strlen($text) < 4 || preg_match('/باقة|اشتراك|عرض سعر|طلب/u', $text) === 1
            || preg_match('/\b(package|subscription|quotation|quote)\b/i', $text) === 1) {
            return null;
        }
        if (preg_match('/رقمي|رقم الهاتف|هاتفي|موبايلي|جوالي|رقم الموبايل|رقم الجوال/u', $text) === 1
            || preg_match('/\b(my phone|phone number|my number)\b/i', $text) === 1) {
            return 'phone';
        }
        if (preg_match('/اسم الشركة|شركتي|اسم المحل|اسم النشاط/u', $text) === 1
            || (preg_match('/الشركة|المحل/u', $text) === 1 && preg_match('/غير|عدل|تعديل|غلط|خطأ|بدل|حدّث|حدث/u', $text) === 1)
            || preg_match('/\b(company name|my company|business name)\b/i', $text) === 1
            || (preg_match('/\bcompany\b/i', $text) === 1 && preg_match('/\b(change|edit|wrong|update)\b/i', $text) === 1)) {
            return 'company_name';
        }
        if (preg_match('/اسمي|الاسم الكامل|غير الاسم|عدل الاسم|تعديل الاسم/u', $text) === 1
            || preg_match('/\b(my name|full name)\b/i', $text) === 1) {
            return 'name';
        }
        if (preg_match('/بياناتي|معلوماتي|ملفي|حسابي|بيانات العميل/u', $text) === 1
            || preg_match('/\b(my profile|my details|my account)\b/i', $text) === 1) {
            return 'card';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function openProfileTarget(Client $client, string $chatId, string $phone, array $session, string $target): void
    {
        if ($target === 'card') {
            $this->showProfile($client, $chatId, $phone, $session);

            return;
        }
        $this->handleCallback($client, $chatId, $phone, $session, 'prof:'.$target);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function showProfile(Client $client, string $chatId, string $phone, array $session): void
    {
        $client = $client->fresh() ?? $client;
        $session['step'] = 'idle';
        unset($session['profile_field']);
        $this->putSession($phone, $session);
        $this->telegram->sendInlineKeyboard(
            $chatId,
            $this->tx(
                "بياناتك:\nالاسم: {$client->name}\nالهاتف: {$client->phone}\nالشركة: {$client->company_name}\nأي حقل بدك تعدله؟",
                "Your details:\nName: {$client->name}\nPhone: {$client->phone}\nCompany: {$client->company_name}\nChoose the field to change.",
            ),
            [
                [['text' => $this->tx('تعديل الاسم', 'Edit name'), 'callback_data' => 'prof:name']],
                [['text' => $this->tx('تعديل الهاتف', 'Edit phone'), 'callback_data' => 'prof:phone']],
                [['text' => $this->tx('تعديل الشركة', 'Edit company'), 'callback_data' => 'prof:company_name']],
                [['text' => $this->tx('القائمة', 'Menu'), 'callback_data' => 'menu:home']],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function saveProfileEdit(Client $client, string $chatId, string $phone, array $session, ?string $text): void
    {
        $field = (string) ($session['profile_field'] ?? '');
        $value = trim((string) $text);
        if ($value === '' || ClientProfileValue::isKeyboardLabel($value) || ! in_array($field, ['name', 'phone', 'company_name'], true)) {
            $this->safeSend($chatId, $this->tx('اكتب القيمة الجديدة.', 'Type the new value.'));

            return;
        }

        $stored = match ($field) {
            'name' => ClientProfileValue::usableName($value),
            'phone' => ClientProfileValue::usablePhone($value),
            default => ClientProfileValue::usableCompanyName($value, $chatId),
        };
        if ($stored === null) {
            $hint = match ($field) {
                'name' => $this->tx('اكتب اسمك، مو رقم ولا خيار من القائمة.', 'Send your full name, not a number or a menu button.'),
                'phone' => $this->tx('الرقم مو واضح، ابعت رقم الموبايل.', 'Send a valid phone number.'),
                default => $this->tx('اكتب اسم الشركة أو المحل.', 'Send the real company name.'),
            };
            $this->safeSend($chatId, $hint);

            return;
        }

        $client->forceFill([$field => $stored])->save();
        $fresh = $client->fresh() ?? $client;
        $this->pushCompletedClientToOdoo($fresh);
        $this->safeSend($chatId, $this->tx('تمام، حدّثنا بياناتك.', 'Your details were updated.'));
        if (filled($session['quote_ref'] ?? null)) {
            $this->safeSend($chatId, $this->tx(
                "بياناتك:\nالاسم: {$fresh->name}\nالهاتف: {$fresh->phone}\nالشركة: {$fresh->company_name}",
                "Your details:\nName: {$fresh->name}\nPhone: {$fresh->phone}\nCompany: {$fresh->company_name}",
            ));
            $this->showQuoteDecision($chatId, $phone, $session);

            return;
        }
        $this->showProfile($fresh, $chatId, $phone, $session);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function beginAsk(string $chatId, string $phone, array &$session): void
    {
        $session['step'] = 'ask';
        $this->putSession($phone, $session);
        $this->safeSend($chatId, $this->tx(
            "اكتب سؤالك عن الخدمات، أو عن الباقة الأنسب لنشاطك.\nالجواب من طريقة عمل الشركة والباقات المنشورة، ومن دون أي معلومة خاصة.",
            "Write your question about the services, or about the package that fits your business.\nThe answer uses how the company works and the published packages, with no private information.",
        ));
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function answerAsk(string $chatId, string $phone, array &$session, string $text): void
    {
        $question = trim($text);
        if ($question === '' || ClientProfileValue::isKeyboardLabel($question)) {
            $this->beginAsk($chatId, $phone, $session);

            return;
        }

        $history = is_array($session['ask_history'] ?? null) ? $session['ask_history'] : [];
        $answer = $this->boundedAnswer($question, $history);
        $history[] = ['role' => 'user', 'text' => mb_substr($question, 0, 400)];
        $history[] = ['role' => 'assistant', 'text' => mb_substr($answer, 0, 400)];
        $session['ask_history'] = array_slice($history, -6);
        $session['step'] = 'idle';
        $this->putSession($phone, $session);
        $this->sendMenu($chatId, $answer);
    }

    /**
     * @param  list<array{role: string, text: string}>  $history
     */
    private function boundedAnswer(string $question, array $history): string
    {
        try {
            $answer = app(GeminiService::class)->answerSiteQuestion(
                $question,
                $this->replyLang,
                app(SiteGuide::class)->brief(),
                $history,
            );
        } catch (Throwable $exception) {
            Log::warning('WhatsApp inquiry failed.', ['error' => $exception->getMessage()]);

            return $this->tx(
                "ما لقيت هذا التفصيل في المعلومات المنشورة.\nتقدر تسأل رقم الدعم: ".self::SUPPORT_PHONE,
                "That detail is not in the published information.\nYou can ask support: ".self::SUPPORT_PHONE,
            );
        }

        return mb_substr($answer, 0, 1200);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function showQuoteDecision(string $chatId, string $phone, array $session): void
    {
        $ref = (string) ($session['quote_ref'] ?? '');
        if ($ref === '') {
            $this->sendMenu($chatId, $this->tx('ما في عرض ناطر قرارك. افتح طلباتي.', 'No quotation is waiting for a decision. Open My requests.'));

            return;
        }
        $session['step'] = 'quote';
        $this->putSession($phone, $session);
        $this->telegram->sendInlineKeyboard($chatId, $this->tx('لإكمال هذا الطلب:', 'To continue this request:'), [
            [['text' => $this->tx('موافقة', 'Approve'), 'callback_data' => 'approve:'.$ref]],
            [['text' => $this->tx('رفض', 'Reject'), 'callback_data' => 'reject:'.$ref]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function continueQuote(Client $client, string $chatId, string $phone, array $session, string $text): void
    {
        $word = $this->quoteWord($text);
        $ref = (string) ($session['quote_ref'] ?? '');
        if ($word === 'approve' && $ref !== '') {
            $this->handleCallback($client, $chatId, $phone, $session, 'approve:'.$ref);

            return;
        }
        if ($word === 'reject' && $ref !== '') {
            $this->handleCallback($client, $chatId, $phone, $session, 'reject:'.$ref);

            return;
        }

        $this->safeSend($chatId, $this->tx(
            "العرض بانتظار قرارك.\n1 للموافقة، ثم يصل رمز الدفع وترسل الوصل.\n2 للرفض، ثم تكتب السبب.",
            "The quotation is waiting for your decision.\n1 approves it, then the payment code arrives and you send the receipt.\n2 rejects it, then you write the reason.",
        ));
        $this->showQuoteDecision($chatId, $phone, $session);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function continueSuggest(Client $client, string $chatId, string $phone, array $session, string $text): void
    {
        $choice = $this->quoteWord($text);
        if ($choice === 'approve') {
            $this->createCatalog(
                $client,
                $chatId,
                $phone,
                $session,
                (int) ($session['suggest_package'] ?? 0),
                (string) ($session['suggest_period'] ?? ''),
            );

            return;
        }
        if ($choice === 'reject') {
            $session['step'] = 'idle';
            unset($session['suggest_package'], $session['suggest_period']);
            $this->putSession($phone, $session);
            $this->showCatalog($client, $chatId, $phone, $session);

            return;
        }

        $this->safeSend($chatId, $this->tx(
            "1 ينشئ الطلب على الباقة المقترحة ويوصلك عرض السعر.\n2 يفتح قائمة الباقات لتختار بنفسك.",
            "1 creates the request for the suggested package and sends the quotation.\n2 opens the package list so you can choose.",
        ));
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function suggestPackage(Client $client, string $chatId, string $phone, array $session, string $text): bool
    {
        if (! $client->profileComplete()) {
            return false;
        }
        $catalog = $this->packageChoices();
        if ($catalog === '') {
            return false;
        }
        $history = is_array($session['ask_history'] ?? null) ? $session['ask_history'] : [];
        $pick = app(GeminiService::class)->recommendPackage($text, $catalog, $history, $this->replyLang);
        if ($pick === null) {
            return false;
        }

        $session['step'] = 'suggest';
        $session['suggest_package'] = $pick['id'];
        $session['suggest_period'] = $pick['period'];
        $history[] = ['role' => 'user', 'text' => mb_substr($text, 0, 400)];
        $history[] = ['role' => 'assistant', 'text' => mb_substr($pick['answer'], 0, 400)];
        $session['ask_history'] = array_slice($history, -6);
        $this->putSession($phone, $session);
        $this->telegram->sendInlineKeyboard($chatId, $pick['answer'], [
            [['text' => $this->tx('أنشئ الطلب', 'Create the request'), 'callback_data' => 'pick:'.$pick['id'].':'.$pick['period']]],
            [['text' => $this->tx('أختار بنفسي', 'I will choose'), 'callback_data' => 'menu:new']],
        ]);

        return true;
    }

    private function packageChoices(): string
    {
        $lines = [];
        PricingPackage::query()
            ->where('is_published', true)
            ->with('subcategory')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(40)
            ->get()
            ->each(function (PricingPackage $package) use (&$lines): void {
                $periods = BillingPeriod::availableFromPrices(is_array($package->prices) ? $package->prices : []);
                if ($periods === [] && $package->price_usd) {
                    $periods = ['one_time'];
                }
                if ($periods === []) {
                    return;
                }
                $name = trim($package->name_ar.' / '.$package->name_en, ' /');
                $subtitle = trim((string) $package->subtitle_ar.' / '.(string) $package->subtitle_en, ' /');
                $lines[] = 'id:'.$package->id.'|'.$name.'|'.$subtitle.'|'.implode(',', $periods);
            });

        return implode("\n", $lines);
    }

    private function openQuotation(Client $client): ?ServiceRequest
    {
        $item = $client->requests()
            ->where('status', RequestStatus::QuotationSent)
            ->latest('id')
            ->first();

        return $item instanceof ServiceRequest ? $item : null;
    }

    private function quoteWord(string $text): ?string
    {
        $text = trim(strtr($text, ['٠' => '0', '١' => '1', '٢' => '2']));
        $folded = mb_strtolower($text);
        if (in_array($text, ['1', 'موافقة', '✅ موافقة', 'نعم'], true) || in_array($folded, ['approve', 'yes', 'accept'], true)) {
            return 'approve';
        }
        if (in_array($text, ['2', 'رفض', '❌ رفض'], true) || in_array($folded, ['reject', 'no'], true)) {
            return 'reject';
        }

        return null;
    }

    private function wantsBiggerPackage(string $text): bool
    {
        return preg_match('/باق[ةه]\s+[أا]كبر|باق[ةه]\s+[أا]غلى|عرض\s+[أا]كبر|bigger package|larger package|larger offer/ui', $text) === 1;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function offerLargerPackage(Client $client, string $chatId, string $phone, array $session, ServiceRequest $request): void
    {
        $request->loadMissing('pricingPackage.subcategory.category');
        $current = $request->pricingPackage;
        $period = (string) ($request->billing_period ?: 'one_time');
        $larger = $current instanceof PricingPackage ? $this->nextPricedPackage($current, $period) : null;
        if (! $larger instanceof PricingPackage) {
            $this->safeSend($chatId, $this->tx(
                'هذه أكبر باقة لهذه الفترة. العرض الحالي ما زال بانتظار موافقتك أو رفضك.',
                'This is the largest package for this period. The current quotation is still waiting for your approval or rejection.',
            ));

            return;
        }

        $price = $this->packagePrice($larger, $period);
        $plan = PaymentPlanResolver::forPackage($larger);
        $name = $larger->name_ar ?: $larger->name_en ?: $larger->slug;
        $periodLabel = BillingPeriod::labelAr($period);
        $request->forceFill([
            'pricing_package_id' => $larger->id,
            'billing_period' => $period,
            'title' => $name,
            'description' => "طلب من الكتالوج: {$name}\nالفترة: {$periodLabel}",
            'payment_plan' => $plan['payment_plan'],
            'requires_full_payment' => $plan['requires_full_payment'],
            'allows_renewal' => $plan['allows_renewal'],
        ])->save();

        $features = collect(is_array($larger->features) ? $larger->features : [])
            ->map(function (mixed $feature): string {
                if (is_string($feature)) {
                    return trim($feature);
                }
                if (! is_array($feature)) {
                    return '';
                }

                return trim((string) ($feature['ar'] ?? $feature['en'] ?? ''));
            })
            ->filter()
            ->take(8)
            ->implode('، ');
        $budgetNote = $plan['requires_full_payment']
            ? 'الميزانية: الدفع كامل قبل بدء العمل'
            : 'الميزانية: دفعة أولى 50٪ ثم المتبقي';
        $packageNotes = $periodLabel.($features !== '' ? "\nيشمل: {$features}" : '')."\n{$budgetNote}";
        $lines = [[
            'title' => $name,
            'amount' => $price,
            'units' => 1,
            'notes' => $packageNotes,
        ]];
        $adBudget = (float) (is_array($larger->reach) ? ($larger->reach['adBudgetUsd'] ?? 0) : 0);
        if ($adBudget > 0 && abs($adBudget - $price) > 0.5) {
            $lines[] = [
                'title' => 'ميزانية الإعلان',
                'amount' => $adBudget,
                'units' => 1,
                'notes' => 'تُحسب ضمن هذا العرض',
            ];
        }

        $session['step'] = 'quote';
        $session['quote_ref'] = ResolveServiceRequest::displayNumber($request);
        $this->putSession($phone, $session);
        app(SendQuotation::class)->handle($request->fresh(['client', 'pricingPackage']) ?? $request, $price, null, 'client:larger-package', null, $lines, true);
    }

    private function nextPricedPackage(PricingPackage $current, string $period): ?PricingPackage
    {
        $currentPrice = $this->packagePrice($current, $period);
        if ($currentPrice === null) {
            return null;
        }

        return PricingPackage::query()
            ->where('is_published', true)
            ->where('subcategory_id', $current->subcategory_id)
            ->whereKeyNot($current->id)
            ->get()
            ->filter(function (PricingPackage $package) use ($period, $currentPrice): bool {
                $price = $this->packagePrice($package, $period);

                return $price !== null && $price > $currentPrice;
            })
            ->sortBy(fn (PricingPackage $package): float => (float) $this->packagePrice($package, $period))
            ->first();
    }

    private function packagePrice(PricingPackage $package, string $period): ?float
    {
        $prices = is_array($package->prices) ? $package->prices : [];
        if (isset($prices[$period]) && is_numeric($prices[$period])) {
            return (float) $prices[$period];
        }
        if ($period === 'one_time' && $package->price_usd !== null) {
            return (float) $package->price_usd;
        }

        return null;
    }

    private function wantsPackage(?string $text): bool
    {
        $text = trim((string) $text);
        if (mb_strlen($text) < 12 || ClientProfileValue::isKeyboardLabel($text)) {
            return false;
        }

        return preg_match('/باقة|اشتراك|محل|نشاط/u', $text) === 1
            || preg_match('/\b(package|subscription|shop)\b/i', $text) === 1;
    }

    private function looksLikeQuestion(string $text): bool
    {
        $text = trim($text);
        if (mb_strlen($text) < 8) {
            return false;
        }
        if (preg_match('/[?؟]/u', $text) === 1) {
            return true;
        }

        return preg_match('/(?:^|\s)(ما|ماذا|كيف|وين|أين|اين|كم|هل|متى|ليش|لماذا|شو|فرق|سعر|أسعار|باقة|خدمات)/u', $text) === 1
            || preg_match('/\b(what|how|where|when|why|which|price|prices|package|services)\b/i', $text) === 1;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function rememberLanguage(?string $text, string $phone, array &$session, Client $client): void
    {
        $detected = ChatLanguage::detect($text);
        if ($detected === null) {
            $stored = (string) ($session['lang'] ?? $client->locale ?? 'ar');
            $this->replyLang = $stored === 'en' ? 'en' : 'ar';

            return;
        }

        $this->replyLang = $detected;
        if (($session['lang'] ?? null) !== $detected) {
            $session['lang'] = $detected;
            $this->putSession($phone, $session);
        }
        if ($client->locale !== $detected) {
            $client->forceFill(['locale' => $detected])->save();
        }
    }

    private function feedbackSkipped(string $text): bool
    {
        $value = mb_strtolower(trim($text));

        return in_array($value, ['تخطي', 'تخطى', 'skip', '-', 'لا', 'بدون'], true);
    }

    private function tx(string $arabic, string $english): string
    {
        return $this->replyLang === 'en' ? $english : $arabic;
    }

    private function statusText(RequestStatus $status): string
    {
        return $this->replyLang === 'en' ? $status->labelEn() : $status->labelAr();
    }

    private function safeSend(string $chatId, string $text): void
    {
        try {
            $this->telegram->send($chatId, $text);
        } catch (Throwable $exception) {
            Log::warning('WhatsApp reply failed.', ['error' => $exception->getMessage()]);
        }
    }
}
