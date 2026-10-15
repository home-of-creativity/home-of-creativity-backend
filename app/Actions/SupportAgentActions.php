<?php

namespace App\Actions;

use App\Models\Client;
use App\Models\PricingPackage;
use App\Models\ServiceRequest;
use App\Support\BillingPeriod;
use App\Support\ClientPhone;
use App\Support\ClientProfileValue;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs what the support agent agreed with the client: opening a request (catalog
 * package or manual notes), saving a phone number, or asking management to cancel.
 */
class SupportAgentActions
{
    public const CREATE = 'create_request';

    public const CREATE_PACKAGE = 'create_package_request';

    public const CANCEL = 'cancel_request';

    public const SAVE_PHONE = 'save_phone';

    public function __construct(
        private SubmitServiceRequest $submitServiceRequest,
        private CreateCatalogRequest $createCatalogRequest,
        private CancelClientRequest $cancelClientRequest,
        private ManagementCancelApproval $approval,
        private NotifyEmployees $notifyEmployees,
    ) {}

    /**
     * @param  array{type?: mixed, title?: mixed, description?: mixed, request?: mixed, reason?: mixed, package?: mixed, period?: mixed, phone?: mixed}  $action
     */
    public function run(Client $client, string $chatId, string $locale, array $action, string $whatsappDigits = ''): ?string
    {
        $type = (string) ($action['type'] ?? '');

        return match ($type) {
            self::CREATE => $this->create(
                $client,
                $locale,
                trim((string) ($action['title'] ?? '')),
                trim((string) ($action['description'] ?? '')),
            ),
            self::CREATE_PACKAGE => $this->createPackage(
                $client,
                $locale,
                trim((string) ($action['package'] ?? $action['request'] ?? '')),
                trim((string) ($action['period'] ?? '')),
                trim((string) ($action['description'] ?? '')),
            ),
            self::CANCEL => $this->cancel(
                $client,
                $chatId,
                $locale,
                trim((string) ($action['request'] ?? '')),
                trim((string) ($action['reason'] ?? '')),
            ),
            self::SAVE_PHONE => $this->takeTypedPhone($client, $locale, trim((string) ($action['phone'] ?? '')), $whatsappDigits),
            default => null,
        };
    }

    /**
     * Saves a number the client typed while talking to the agent.
     */
    public function takeTypedPhone(Client $client, string $locale, string $value, string $whatsappDigits): ?string
    {
        if (ClientPhone::wantsCurrentNumber($value)) {
            $current = ClientPhone::fromCallerDigits($whatsappDigits);
            if ($current === null) {
                return $this->tx($locale,
                    'واتساب ما بيبعتلنا رقمك من هالمحادثة. ابعت رقم موبايلك مع مفتاح الدولة، متل +963944123456.',
                    'WhatsApp does not send your number on this chat. Send your mobile with the country code, such as +963944123456.',
                );
            }

            return $this->storePhone($client, $locale, $current);
        }

        if (! ClientProfileValue::looksLikePhone($value)) {
            return null;
        }

        $read = ClientPhone::interpret($value);
        if ($read['status'] === 'ready') {
            return $this->storePhone($client, $locale, (string) $read['phone']);
        }
        if ($read['status'] === 'country') {
            return $this->tx($locale,
                'الرقم بدون مفتاح دولة. ابعتو كامل مع المفتاح، متل +963'.($read['national'] ?? '').'.',
                'That number has no country code. Send it in full, such as +963'.($read['national'] ?? '').'.',
            );
        }

        return $this->tx($locale,
            'الرقم مو واضح. ابعت رقم موبايلك مع مفتاح الدولة، متل +963944123456.',
            'That number is unclear. Send your mobile with the country code, such as +963944123456.',
        );
    }

    private function create(Client $client, string $locale, string $title, string $description): ?string
    {
        if ($title === '' || $description === '') {
            return null;
        }
        if (mb_strlen($description) < 40) {
            return $this->tx($locale,
                'الملاحظات لسا ناقصة. خبرني بكل التفاصيل: شو بدك، الكمية أو المدة، والهدف أو الموعد إن وجد، وبعدين بأكد معك قبل ما افتح الطلب.',
                'The notes are still short. Tell me every detail: what you need, quantity or duration, and the goal or deadline if any, then I will confirm before opening it.',
            );
        }
        $gate = $this->profileGate($client, $locale);
        if ($gate !== null) {
            return $gate;
        }
        if (! Cache::add('hoc:agent-create:'.$client->id.':'.md5(mb_strtolower($title)), 1, now()->addMinutes(10))) {
            return null;
        }

        try {
            $request = $this->submitServiceRequest->handle($client, [
                'title' => mb_substr($title, 0, 120),
                'description' => mb_substr($description, 0, 4000),
                'source' => $client->requestSource(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Support agent could not open a request.', ['error' => $exception->getMessage()]);

            return $this->tx($locale,
                'ما قدرت افتح الطلب هلق. حولت تفاصيله لفريق المبيعات ورح يتواصلوا معك.',
                'I could not open the request now. Sales have the details and will contact you.',
            );
        }

        $ref = ResolveServiceRequest::displayNumber($request);
        $msg = $this->tx($locale,
            "فتحتلك الطلب #{$ref}: {$request->title}.\nفريق المبيعات رح يدرسه ويبعتلك عرض السعر هون.",
            "Request #{$ref} is open: {$request->title}.\nSales will review it and send the quotation here.",
        );

        return $msg.$this->phoneFollowUp($client, $locale);
    }

    private function createPackage(Client $client, string $locale, string $packageRef, string $period, string $notes): ?string
    {
        $packageId = (int) preg_replace('/\D+/', '', $packageRef);
        if ($packageId < 1) {
            return $this->tx($locale,
                'ما قدرت اعرف أي باقة تقصد. اختر باقة من اللي ذكرتها، أو اكتب رقمها.',
                'I could not tell which package you mean. Pick one I listed, or send its id.',
            );
        }
        $gate = $this->profileGate($client, $locale);
        if ($gate !== null) {
            return $gate;
        }

        $package = PricingPackage::query()->whereKey($packageId)->where('is_published', true)->first();
        if (! $package instanceof PricingPackage) {
            return $this->tx($locale,
                'هالباقة مو منشورة ع الموقع هلق. فيك تختار باقة تانية أو نفتح طلب يدوي بملاحظاتك.',
                'That package is not on the website right now. Pick another, or we open a manual request with your notes.',
            );
        }

        $prices = is_array($package->prices) ? $package->prices : [];
        $periods = BillingPeriod::availableFromPrices($prices);
        if ($periods === [] && $package->price_usd) {
            $periods = ['one_time'];
        }
        if ($period === '' || ! in_array($period, $periods, true)) {
            $period = $periods[0] ?? 'one_time';
        }

        $dedupe = 'hoc:agent-pkg:'.$client->id.':'.$package->id.':'.$period;
        if (! Cache::add($dedupe, 1, now()->addMinutes(10))) {
            return null;
        }

        try {
            $request = $this->createCatalogRequest->handle($client, $package, $period);
        } catch (ValidationException $exception) {
            Log::info('Support agent package request refused.', ['error' => $exception->getMessage()]);

            return $this->tx($locale,
                'ما قدرت افتح هالباقة هلق. خبرني إذا بدنا نفتح طلب يدوي بملاحظاتك.',
                'I could not open that package now. Tell me if you want a manual request with your notes.',
            );
        } catch (Throwable $exception) {
            Log::warning('Support agent could not open a package request.', ['error' => $exception->getMessage()]);

            return $this->tx($locale,
                'ما قدرت افتح الطلب هلق. حولت التفاصيل لفريق المبيعات ورح يتواصلوا معك.',
                'I could not open the request now. Sales have the details and will contact you.',
            );
        }

        if ($notes !== '') {
            $request->forceFill([
                'description' => trim($request->description."\n\nملاحظات العميل:\n".mb_substr($notes, 0, 3500)),
            ])->save();
        }

        $ref = ResolveServiceRequest::displayNumber($request);
        $name = $package->name_ar ?: $package->name_en ?: $package->slug;
        $periodLabel = BillingPeriod::label($period, $locale);
        $msg = $this->createCatalogRequest->quotationDelivered
            ? $this->tx($locale,
                "فتحتلك الطلب #{$ref}: {$name} ({$periodLabel}).\nعرض السعر وصل لهون.",
                "Request #{$ref} is open: {$name} ({$periodLabel}).\nThe quotation is here.",
            )
            : $this->tx($locale,
                "فتحتلك الطلب #{$ref}: {$name} ({$periodLabel}).\nفريق المبيعات رح يبعتلك عرض السعر هون.",
                "Request #{$ref} is open: {$name} ({$periodLabel}).\nSales will send the quotation here.",
            );

        return $msg.$this->phoneFollowUp($client, $locale);
    }

    private function cancel(Client $client, string $chatId, string $locale, string $ref, string $reason): string
    {
        $request = ResolveServiceRequest::ownedBy($client, $ref);
        if (! $request instanceof ServiceRequest) {
            return $this->tx($locale,
                'ما لقيت طلب بهالرقم عندك. ابعتلي رقم الطلب متل #12.',
                'I could not find that request on your account. Send its number, such as #12.',
            );
        }
        $number = ResolveServiceRequest::displayNumber($request);

        $refusal = $this->cancelClientRequest->refusal($request);
        if ($refusal === 'state' || $refusal === 'closed') {
            return $refusal === 'closed'
                ? $this->tx($locale, "الطلب #{$number} ما بينلغى بعد ٢٤ ساعة من الفاتورة.", "Request #{$number} cannot be cancelled 24 hours after the invoice.")
                : $this->tx($locale, "الطلب #{$number} بحالته الحالية ما بينلغى.", "Request #{$number} cannot be cancelled in its current state.");
        }
        if ($refusal === 'paid') {
            $this->notifyEmployees->support(implode("\n", array_filter([
                'عميل بدو يلغي طلب مدفوع',
                'العميل: '.($client->company_name ?: $client->name).' '.($client->phone ?: ''),
                "الطلب #{$number}: {$request->title}",
                $reason !== '' ? 'السبب: '.$reason : null,
            ])));

            return $this->tx($locale,
                "الطلب #{$number} مدفوع، فإلغاؤه بيحتاج فريق المبيعات. بعتتلهم طلبك ورح يتواصلوا معك.",
                "Request #{$number} is paid, so sales handle its cancellation. I sent them your request and they will contact you.",
            );
        }
        if ($this->approval->pending($request)) {
            return $this->tx($locale,
                "طلب إلغاء #{$number} لسا عند الإدارة. رح خبرك بردهم هون.",
                "The cancel request for #{$number} is still with management. I will tell you their answer here.",
            );
        }

        $this->approval->ask($request, $client, $chatId, $locale, $reason);

        return $this->tx($locale,
            "بعتت طلب إلغاء الطلب #{$number} للإدارة. أول ما يردوا رح خبرك هون.",
            "I sent the cancel request for #{$number} to management. I will tell you their answer here.",
        );
    }

    private function storePhone(Client $client, string $locale, string $stored): string
    {
        $client->forceFill(['phone' => $stored])->save();

        return $this->tx($locale,
            'تمام، سجلت رقمك '.$stored.'.',
            'Done, I saved your number '.$stored.'.',
        );
    }

    private function profileGate(Client $client, string $locale): ?string
    {
        if (! filled($client->name)) {
            return $this->tx($locale,
                'قبل ما نفتح الطلب خبرني باسمك الكامل.',
                'Before we open the request, tell me your full name.',
            );
        }
        if (! filled($client->company_name)) {
            return $this->tx($locale,
                'قبل ما نفتح الطلب خبرني باسم الشركة أو النشاط.',
                'Before we open the request, tell me the company or business name.',
            );
        }

        return null;
    }

    private function phoneFollowUp(Client $client, string $locale): string
    {
        if (filled($client->phone) && ClientPhone::interpret((string) $client->phone)['status'] !== 'invalid') {
            return '';
        }

        return $this->tx($locale,
            "\nخبرني برقم موبايلك مع مفتاح الدولة، متل +963944123456، لحتى نستلم عليه التواصل والعرض.",
            "\nSend your mobile with the country code, such as +963944123456, so we can reach you and send the quotation there.",
        );
    }

    private function tx(string $locale, string $arabic, string $english): string
    {
        return $locale === 'en' ? $english : $arabic;
    }
}
