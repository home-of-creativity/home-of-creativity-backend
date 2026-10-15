<?php

namespace App\Actions;

use App\Models\Client;
use App\Models\ServiceRequest;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs what the support agent agreed with the client: opening a request, or asking
 * management to cancel one. Returns the message for the client, with the real number.
 */
class SupportAgentActions
{
    public const CREATE = 'create_request';

    public const CANCEL = 'cancel_request';

    public function __construct(
        private SubmitServiceRequest $submitServiceRequest,
        private CancelClientRequest $cancelClientRequest,
        private ManagementCancelApproval $approval,
        private NotifyEmployees $notifyEmployees,
    ) {}

    /**
     * @param  array{type?: mixed, title?: mixed, description?: mixed, request?: mixed, reason?: mixed}  $action
     */
    public function run(Client $client, string $chatId, string $locale, array $action): ?string
    {
        $type = (string) ($action['type'] ?? '');

        return match ($type) {
            self::CREATE => $this->create($client, $locale, trim((string) ($action['title'] ?? '')), trim((string) ($action['description'] ?? ''))),
            self::CANCEL => $this->cancel($client, $chatId, $locale, trim((string) ($action['request'] ?? '')), trim((string) ($action['reason'] ?? ''))),
            default => null,
        };
    }

    private function create(Client $client, string $locale, string $title, string $description): ?string
    {
        if ($title === '' || $description === '') {
            return null;
        }
        if (! $client->profileComplete()) {
            return $this->tx($locale,
                'قبل ما نفتح الطلب لازم نكمل بياناتك: الاسم والرقم واسم الشركة. اكتب «رجوع للبوت» وكملها.',
                'Before opening the request we need your name, phone, and company. Write «back to bot» to finish them.',
            );
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

        return $this->tx($locale,
            "فتحتلك الطلب #{$ref}: {$request->title}.\nفريق المبيعات رح يدرسه ويبعتلك عرض السعر هون.",
            "Request #{$ref} is open: {$request->title}.\nSales will review it and send the quotation here.",
        );
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

    private function tx(string $locale, string $arabic, string $english): string
    {
        return $locale === 'en' ? $english : $arabic;
    }
}
