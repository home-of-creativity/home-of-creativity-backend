<?php

namespace App\Actions;

use App\Enums\ClickUpSyncEvent;
use App\Enums\EmployeeProfession;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\DriveDelivery;
use App\Models\PricingPackage;
use App\Models\RequestFile;
use App\Models\ServiceRequest;
use App\Services\RequestStatusTransitionService;
use App\Services\TelegramNotifier;
use App\Services\WhatsAppCloudClient;
use App\Support\BillingPeriod;
use App\Support\ClientProfileValue;
use App\Support\PricingCatalog;
use App\Support\ResolveServiceRequest;
use App\Support\StatusLabel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class HandleWhatsAppInbound
{
    private const SESSION_TTL_SECONDS = 7 * 24 * 3600;

    private const SUPPORT_PHONE = '0947823488';

    /** @var list<string> */
    private const NAV_NEW = ['طلب جديد', '🆕 طلب جديد', 'menu:new'];

    /** @var list<string> */
    private const NAV_MINE = ['طلباتي', '📋 طلباتي', 'menu:mine'];

    /** @var list<string> */
    private const NAV_HELP = ['الدعم', '💬 دعم', 'menu:help'];

    public function __construct(
        private ResolveTelegramClient $resolveTelegramClient,
        private TelegramNotifier $telegram,
        private WhatsAppCloudClient $whatsApp,
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

        $this->whatsApp->markRead($wamid);

        $chatId = Client::whatsappKey($phone);
        $client = $this->ensureClient($chatId, $phone, $profileName);
        $session = $this->session($phone);

        try {
            $callback = $this->callbackId($message);
            $text = $this->messageText($message);
            $media = $this->messageMedia($message);

            if ($callback !== null) {
                $this->handleCallback($client, $chatId, $phone, $session, $callback);

                return;
            }

            if ($this->isNav($text, self::NAV_NEW)) {
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
            if ($this->isNav($text, self::NAV_HELP)) {
                $this->resetCompose($session);
                $this->putSession($phone, $session);
                $this->sendMenu($chatId, 'رقم الدعم: '.self::SUPPORT_PHONE);

                return;
            }

            $step = (string) ($session['step'] ?? 'idle');
            if (in_array($step, ['name', 'phone', 'company_name'], true)) {
                $this->captureProfile($client, $chatId, $phone, $session, $text);

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

            if (! $this->gateProfile($client, $chatId, $session, greet: true)) {
                return;
            }

            if ($media !== null && $this->pendingReceiptRequest($client) !== null) {
                $session['step'] = 'receipt';
                $session['receipt_ref'] = ResolveServiceRequest::displayNumber($this->pendingReceiptRequest($client));
                $this->storeReceipt($client, $chatId, $phone, $session, $media);

                return;
            }

            $this->sendMenu(
                $chatId,
                'أهلاً '.($client->name ?: '')." في Home of Creativity.\nهذا بوت العملاء: تطلب الخدمة، تستلم عرض السعر، وتتابع حالة طلبك من هنا.\nاختر من الأزرار أدناه لطلب جديد أو متابعة طلباتك.",
            );
        } catch (Throwable $exception) {
            Log::error('WhatsApp conversation failed.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);
            $this->safeSend($chatId, 'تعذر تنفيذ الطلب. حاول مرة أخرى أو اضغط الدعم.');
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
                    'أهلاً '.$client->name." في Home of Creativity.\nهذا بوت العملاء: تطلب الخدمة، تستلم عرض السعر، وتتابع حالة طلبك من هنا.\nاختر من الأزرار أدناه لطلب جديد أو متابعة طلباتك.",
                );
            }

            return true;
        }

        $field = $missing[0];
        $session['step'] = $field;
        $this->putSession(Client::whatsappPhoneFromKey($chatId), $session);

        $intro = $greet
            ? 'أهلاً '.($client->name ?: '')." في Home of Creativity.\nهذا بوت العملاء: تطلب الخدمة، تستلم عرض السعر، وتتابع حالة طلبك من هنا.\n\nقبل أول طلب نحتاج رقم هاتفك واسم الشركة.\n"
            : '';

        $prompt = match ($field) {
            'name' => 'ما اسمك الكامل؟',
            'phone' => "نطلب رقم الهاتف لنتواصل معك عند صدور العرض أو أي استفسار عن الطلب.\nما رقم هاتفك؟",
            default => "نطلب اسم الشركة لنصدر العرض والفاتورة باسم جهتك ونحفظ الطلب في ملفك.\nما اسم الشركة؟",
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
            $this->safeSend($chatId, 'أرسل اسمك الكامل، وليس رقماً أو زر قائمة.');

            return;
        }
        if ($field === 'phone' && ClientProfileValue::usablePhone($value) === null) {
            $this->safeSend($chatId, 'أرسل رقم هاتف صالح.');

            return;
        }
        if ($field === 'company_name' && ClientProfileValue::usableCompanyName($value, $chatId) === null) {
            $this->safeSend($chatId, 'أرسل اسم الشركة الحقيقي.');

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

        $this->sendMenu($chatId, 'تم حفظ بياناتك. اختر من الأزرار أدناه.');
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function handleCallback(Client $client, string $chatId, string $phone, array $session, string $data): void
    {
        if ($this->isNav($data, self::NAV_NEW) || $data === 'menu:new') {
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
            $this->sendMenu($chatId, 'رقم الدعم: '.self::SUPPORT_PHONE);

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
            $this->telegram->sendInlineKeyboard($chatId, 'ما عنوان الطلب؟', [[
                ['text' => 'السابق', 'callback_data' => 'back'],
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
            $this->createCatalog($client, $chatId, (int) ($parts[1] ?? 0), $parts[2] ?? null);

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
            $this->runOwned($client, substr($data, 8), function (ServiceRequest $request) use ($chatId): void {
                $this->approveQuotation->handle($request);
                $this->safeSend($chatId, 'تم تسجيل موافقتك على العرض.');
            });

            return;
        }
        if (str_starts_with($data, 'reject:')) {
            $ref = substr($data, 7);
            $session['step'] = 'reject_pick';
            $session['reject_ref'] = $ref;
            $this->putSession($phone, $session);
            $this->telegram->sendInlineKeyboard($chatId, 'سبب الرفض؟', [
                [
                    ['text' => 'السعر غالي', 'callback_data' => 'rjprice:'.$ref],
                    ['text' => 'تأخير بالرد', 'callback_data' => 'rjdelay:'.$ref],
                ],
                [['text' => 'غير ذلك', 'callback_data' => 'rjother:'.$ref]],
            ]);

            return;
        }
        if (str_starts_with($data, 'rjprice:') || str_starts_with($data, 'rjdelay:')) {
            $reason = str_starts_with($data, 'rjprice:') ? 'السعر غالي' : 'تأخير بالرد';
            $ref = explode(':', $data, 2)[1] ?? '';
            $this->runOwned($client, $ref, function (ServiceRequest $request) use ($chatId, $reason): void {
                $this->rejectQuotation->handle($request, $reason);
                $this->sendMenu($chatId, 'تم رفض العرض.');
            });

            return;
        }
        if (str_starts_with($data, 'rjother:')) {
            $session['step'] = 'reject_reason';
            $session['reject_ref'] = explode(':', $data, 2)[1] ?? '';
            $this->putSession($phone, $session);
            $this->safeSend($chatId, 'اكتب سبب الرفض.');

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
            $this->safeSend($chatId, 'ما هي التعديلات المطلوبة');

            return;
        }
        if (str_starts_with($data, 'complete:')) {
            $this->runOwned($client, substr($data, 9), function (ServiceRequest $request) use ($chatId): void {
                abort_unless($request->status === RequestStatus::ReadyForReview, 422, 'Only ready-for-review requests can be completed.');
                $this->completeRequest->handle($request, 'client');
                $this->sendMenu($chatId, 'تم اعتماد التسليم. شكراً لك.');
            });

            return;
        }
        if (str_starts_with($data, 'receipt_hint:')) {
            $session['step'] = 'receipt';
            $session['receipt_ref'] = substr($data, 13);
            $this->putSession($phone, $session);
            $this->safeSend($chatId, 'أرسل وصل الدفع كصورة أو PDF.');

            return;
        }
        if (str_starts_with($data, 'renew:')) {
            $this->runOwned($client, substr($data, 6), function (ServiceRequest $request) use ($chatId): void {
                $this->renewSubscription->handle($request);
                $this->safeSend($chatId, 'بدأ تجديد الاشتراك. سيصلك عرض السعر.');
            });

            return;
        }
        if (str_starts_with($data, 'norenew:')) {
            $this->runOwned($client, substr($data, 8), function (ServiceRequest $request) use ($chatId): void {
                $this->declineRenewal->handle($request);
                $this->sendMenu($chatId, 'تم تسجيل أنك لن تجدّد الآن.');
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
                $this->safeSend($chatId, 'تم إبلاغ الفريق باهتمامك.');
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
                $this->sendMenu($chatId, 'تم إلغاء الطلب.');
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
                $this->createCatalog($client, $chatId, (int) ($first['id'] ?? 0), null);

                return;
            }
            $rows = [];
            foreach ($periods as $period) {
                $rows[] = [[
                    'text' => BillingPeriod::labelAr($period),
                    'callback_data' => 'per:'.(int) ($first['id'] ?? 0).':'.$period,
                ]];
            }
            $rows[] = [['text' => 'السابق', 'callback_data' => 'back']];
            $rows[] = [['text' => 'طلب يدوي', 'callback_data' => 'cman']];
            $this->telegram->sendInlineKeyboard(
                $chatId,
                'اختر مدة الاشتراك لـ '.((string) ($first['name'] ?? '')).':',
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
            $rows[] = [['text' => 'عرض المزيد', 'callback_data' => "more:{$scope}:{$parent}:".($offset + PricingCatalog::PAGE_SIZE)]];
        }
        $rows[] = [['text' => 'السابق', 'callback_data' => 'back']];
        $rows[] = [['text' => 'طلب يدوي', 'callback_data' => 'cman']];

        $title = 'اختر الفئة:';
        if ($categoryId) {
            $title = 'اختر الفئة الفرعية أو الباقة:';
        }
        if ($subcategoryId) {
            $title = 'اختر الباقة:';
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
        $this->sendMenu($chatId, 'تم الرجوع للقائمة الرئيسية.');
    }

    private function createCatalog(Client $client, string $chatId, int $packageId, ?string $period): void
    {
        abort_unless($client->profileComplete(), 422, 'Complete your name, phone, and company first.');
        $this->safeSend($chatId, "جاري إنشاء طلبك وانتظار عرض السعر…\nيرجى الانتظار لحظات، لا تغلق المحادثة.");

        $package = PricingPackage::query()->with('subcategory.category')->findOrFail($packageId);
        $serviceRequest = $this->createCatalogRequest->handle($client, $package, $period);
        $fresh = $serviceRequest->fresh() ?? $serviceRequest;
        $number = $fresh->number;
        $label = $fresh->status->labelAr();
        if ($this->createCatalogRequest->quotationDelivered) {
            $this->sendMenu($chatId, "تم إنشاء الطلب #{$number}.\nالحالة: {$label}.\nسيصلك عرض السعر الآن في المحادثة.");

            return;
        }

        $this->sendMenu($chatId, "تم إنشاء الطلب #{$number}.\nالحالة: {$label}.\nإذا لم يصلك عرض السعر خلال لحظات، افتح طلباتي.");
    }

    private function listRequests(Client $client, string $chatId, int $offset = 0): void
    {
        $items = $client->requests()
            ->with('pricingPackage')
            ->latest('id')
            ->get();

        if ($items->isEmpty()) {
            $this->sendMenu($chatId, 'لا توجد طلبات بعد.');

            return;
        }

        $pageSize = 10;
        $shown = $items->slice($offset, $pageSize);
        $header = $items->count() <= $pageSize
            ? 'طلباتك:'
            : 'طلباتك ('.($offset + 1).'–'.($offset + $shown->count()).' من '.$items->count().'):';
        $this->safeSend($chatId, $header);

        foreach ($shown as $item) {
            $label = StatusLabel::requestAr($item->status->value);
            $package = $item->pricingPackage?->name_ar ?: $item->pricingPackage?->name_en ?: 'طلب يدوي';
            $period = filled($item->billing_period) ? BillingPeriod::labelAr((string) $item->billing_period) : '';
            $lines = [
                "#{$item->number} — {$item->title}",
                'الحالة: '.$label,
                'الباقة: '.$package.($period !== '' ? " — {$period}" : ''),
            ];
            if ($item->amount_paid !== null || $item->amount_remaining !== null) {
                $lines[] = 'المدفوع: '.($item->amount_paid ?: 0).' USD — المتبقي: '.($item->amount_remaining ?: 0).' USD';
            }
            if ($item->receipt_reupload_required || $item->acceptsReceiptUpload()) {
                $lines[] = '📎 أرسل وصل الدفع كصورة أو PDF';
            }

            $ref = ResolveServiceRequest::displayNumber($item);
            $rows = [];
            if ($item->status === RequestStatus::ReadyForReview) {
                $rows[] = [['text' => 'اعتماد التسليم', 'callback_data' => 'complete:'.$ref]];
            }
            if ($item->receipt_reupload_required || $item->acceptsReceiptUpload()) {
                $rows[] = [['text' => 'رفع وصل الدفع', 'callback_data' => 'receipt_hint:'.$ref]];
            }
            if ($item->canRenew()) {
                $rows[] = [
                    ['text' => 'تجديد', 'callback_data' => 'renew:'.$item->number],
                    ['text' => 'لن أجدد', 'callback_data' => 'norenew:'.$item->number],
                ];
            }

            if ($rows === []) {
                $this->safeSend($chatId, implode("\n", $lines));
            } else {
                $this->telegram->sendInlineKeyboard($chatId, implode("\n", $lines), $rows);
            }
        }

        $next = $offset + $pageSize;
        if ($next < $items->count()) {
            $this->telegram->sendInlineKeyboard($chatId, 'عرض الطلبات الأقدم:', [[
                ['text' => 'عرض الأقدم', 'callback_data' => 'more_reqs:'.$next],
            ]]);
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function captureTitle(Client $client, string $chatId, string $phone, array $session, ?string $text): void
    {
        $value = trim((string) $text);
        if ($value === '') {
            $this->safeSend($chatId, 'ما عنوان الطلب؟');

            return;
        }
        $session['title'] = $value;
        $session['step'] = 'body';
        $session['attachments'] = [];
        $session['description'] = '';
        $this->putSession($phone, $session);
        $this->telegram->sendInlineKeyboard(
            $chatId,
            "صف المطلوب.\nيمكنك إرسال نصاً أو صوراً أو ملفات (JPG, PNG, PDF).\nعند الانتهاء أرسل «تم الإرسال».",
            [[['text' => 'السابق', 'callback_data' => 'back']]],
        );
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array{file_name: string, file_base64: string, mime_type: string}|null  $media
     */
    private function captureBody(Client $client, string $chatId, string $phone, array $session, ?string $text, ?array $media): void
    {
        $trimmed = trim((string) $text);
        if (in_array($trimmed, ['تم الإرسال', '✅ تم الإرسال', 'تم'], true)) {
            $this->submitManual($client, $chatId, $phone, $session);

            return;
        }

        if ($media !== null) {
            $attachments = $session['attachments'] ?? [];
            if (count($attachments) >= 5) {
                $this->safeSend($chatId, 'الحد الأقصى 5 مرفقات.');

                return;
            }
            $attachments[] = $media;
            $session['attachments'] = $attachments;
            $this->putSession($phone, $session);
            $this->safeSend($chatId, 'تم حفظ المرفق ('.count($attachments).'/5). أرسل وصفاً أو اضغط تم الإرسال.');

            return;
        }

        if ($trimmed !== '') {
            $session['description'] = $trimmed;
            $this->putSession($phone, $session);
            $this->safeSend($chatId, 'تم حفظ الوصف. أرسل مرفقات إن وجدت، ثم أرسل «تم الإرسال».');
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
            $this->safeSend($chatId, 'العنوان ووصف أو مرفق واحد على الأقل مطلوبان.');

            return;
        }

        $serviceRequest = $this->submitServiceRequest->handle($client, [
            'title' => $title,
            'description' => $description !== '' ? $description : 'انظر المرفقات.',
            'source' => $client->requestSource(),
            'attachments' => $attachments,
        ]);
        $this->resetCompose($session);
        $this->putSession($phone, $session);
        $fresh = $serviceRequest->fresh() ?? $serviceRequest;
        $this->sendMenu($chatId, "تم إنشاء الطلب #{$fresh->number}.\nالحالة: {$fresh->status->labelAr()}.");
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function finishReject(Client $client, string $chatId, string $phone, array $session, ?string $text): void
    {
        $reason = trim((string) $text);
        if ($reason === '') {
            $this->safeSend($chatId, 'اكتب سبب الرفض.');

            return;
        }
        $this->runOwned($client, (string) ($session['reject_ref'] ?? ''), function (ServiceRequest $request) use ($chatId, $reason): void {
            $this->rejectQuotation->handle($request, $reason);
            $this->sendMenu($chatId, 'تم رفض العرض.');
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
            $this->safeSend($chatId, 'ما هي التعديلات المطلوبة');

            return;
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
            $this->sendMenu($chatId, 'تم إرسال طلب التعديل للفريق.');
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
            $this->sendMenu($chatId, 'تم استلام وصل الدفع. سيراجعه الفريق.');
        });
        $session['step'] = 'idle';
        unset($session['receipt_ref']);
        $this->putSession($phone, $session);
    }

    private function approveFile(Client $client, string $chatId, string $payload): void
    {
        if (! str_contains($payload, ':')) {
            $this->safeSend($chatId, 'تعذر قراءة ملف الموافقة.');

            return;
        }
        [$ref, $deliveryId] = explode(':', $payload, 2);
        $this->runOwned($client, $ref, function (ServiceRequest $request) use ($deliveryId, $chatId): void {
            $delivery = DriveDelivery::query()
                ->where('id', $deliveryId)
                ->where('request_id', $request->id)
                ->firstOrFail();
            $this->approveDriveDelivery->handle($request, $delivery);
            $this->safeSend($chatId, 'تم تسجيل موافقتك على الملف.');
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
        if (! is_array($node) || ! filled($node['id'] ?? null)) {
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
        $this->telegram->sendInlineKeyboard($chatId, $text, [[
            ['text' => 'طلب جديد', 'callback_data' => 'menu:new'],
            ['text' => 'طلباتي', 'callback_data' => 'menu:mine'],
            ['text' => 'الدعم', 'callback_data' => 'menu:help'],
        ]]);
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
