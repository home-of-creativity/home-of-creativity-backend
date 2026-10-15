<?php

namespace App\Actions;

use App\Contracts\WhatsAppMessenger;
use App\Models\Client;
use App\Models\ServiceRequest;
use App\Services\TelegramNotifier;
use App\Support\ClientPhone;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A cancel the support agent takes from a client waits for management on WhatsApp:
 * 1 approves and cancels the request, 2 keeps it. The answer, and an optional note
 * management types next, go back to the client's chat.
 */
class ManagementCancelApproval
{
    public const WAIT_DAYS = 7;

    private const NOTE_MINUTES = 30;

    public function __construct(
        private WhatsAppMessenger $whatsApp,
        private TelegramNotifier $telegram,
        private NotifyEmployees $notifyEmployees,
        private CancelClientRequest $cancelClientRequest,
    ) {}

    public function pending(ServiceRequest $request): bool
    {
        return Cache::has($this->pendingKey($request->id));
    }

    /**
     * Sends the cancel to the management numbers. False when no WhatsApp number took it
     * and the admins were told by the fallback instead.
     */
    public function ask(ServiceRequest $request, Client $client, string $chatId, string $locale, string $reason): bool
    {
        $token = Str::lower(Str::random(10));
        Cache::put($this->approvalKey($token), [
            'request_id' => $request->id,
            'client_id' => $client->id,
            'chat_id' => $chatId,
            'locale' => $locale === 'en' ? 'en' : 'ar',
        ], now()->addDays(self::WAIT_DAYS));
        Cache::put($this->pendingKey($request->id), $token, now()->addDays(self::WAIT_DAYS));

        $ref = ResolveServiceRequest::displayNumber($request);
        $text = implode("\n", array_filter([
            'طلب إلغاء من عميل على واتساب',
            'العميل: '.($client->company_name ?: $client->name).' '.($client->phone ?: ''),
            "الطلب #{$ref}: {$request->title}",
            'الحالة: '.$request->status->forClient()->labelAr(),
            $reason !== '' ? 'السبب: '.$reason : null,
            '',
            'رد بـ 1 للموافقة على الإلغاء، أو 2 للرفض.',
        ], fn (?string $line): bool => $line !== null));

        $sent = 0;
        foreach ($this->numbers() as $number) {
            try {
                $this->whatsApp->sendList($number, $text, [
                    ['text' => 'موافق على الإلغاء', 'callback_data' => 'cxok:'.$token],
                    ['text' => 'رفض الإلغاء', 'callback_data' => 'cxno:'.$token],
                ]);
                $sent++;
            } catch (Throwable $exception) {
                Log::warning('Cancel approval to management failed.', ['error' => $exception->getMessage()]);
            }
        }
        if ($sent > 0) {
            return true;
        }

        $this->notifyEmployees->management($text."\n\nواتساب الإدارة غير متاح، فقرروا من لوحة التحكم.");

        return false;
    }

    /**
     * Handles a management number's choice or note. False when the message is not for
     * an approval, so the normal bot answers it.
     */
    public function handleManagement(string $phone, ?string $callback, ?string $text): bool
    {
        if (! $this->isManagement($phone)) {
            return false;
        }
        if (is_string($callback) && preg_match('/^cx(ok|no):([a-z0-9]{10})$/', $callback, $match) === 1) {
            $this->decide($phone, $match[1] === 'ok', $match[2]);

            return true;
        }
        $note = trim((string) $text);
        $waiting = Cache::get($this->noteKey($phone));
        if ($note === '' || ! is_array($waiting)) {
            return false;
        }
        Cache::forget($this->noteKey($phone));
        $this->tellClient((string) $waiting['chat_id'], (string) $waiting['locale'],
            'ملاحظة من الإدارة بخصوص الطلب #'.$waiting['ref'].":\n".$note,
            'A note from management about request #'.$waiting['ref'].":\n".$note,
        );
        $this->tellManagement($phone, 'وصلت ملاحظتك للعميل.');

        return true;
    }

    /** `$phone` is the sender as WhatsApp sends it, digits only. */
    public function isManagement(string $phone): bool
    {
        $digits = $this->digits($phone);

        return $digits !== '' && in_array($digits, $this->numbers(), true);
    }

    private function decide(string $phone, bool $approve, string $token): void
    {
        $approval = Cache::pull($this->approvalKey($token));
        $request = is_array($approval) ? ServiceRequest::query()->with('client')->find($approval['request_id'] ?? 0) : null;
        if (! $request instanceof ServiceRequest) {
            $this->tellManagement($phone, 'طلب الإلغاء هاد انحسم من قبل أو انتهت مدته.');

            return;
        }
        Cache::forget($this->pendingKey($request->id));
        $ref = ResolveServiceRequest::displayNumber($request);
        $chatId = (string) $approval['chat_id'];
        $locale = (string) $approval['locale'];

        if (! $approve) {
            $this->tellClient($chatId, $locale,
                "الإدارة ما وافقت على إلغاء الطلب #{$ref}، والطلب مكمل.",
                "Management did not approve cancelling request #{$ref}. The request continues.",
            );
            $this->waitForNote($phone, $chatId, $locale, $ref);
            $this->tellManagement($phone, "خبرت العميل إنو إلغاء الطلب #{$ref} انرفض.\nإذا بدك تبعتله السبب اكتبه برسالة وحدة هلق.");

            return;
        }

        try {
            $this->cancelClientRequest->handle($request, 'WhatsApp support agent, approved by management');
        } catch (ValidationException $exception) {
            $why = (string) collect($exception->errors())->flatten()->first();
            $this->tellClient($chatId, $locale,
                "الإدارة وافقت على إلغاء الطلب #{$ref}، بس ما انلغى من هون: {$why}\nرح يتواصل معك الفريق.",
                "Management approved cancelling request #{$ref}, but it could not be cancelled here: {$why}\nThe team will contact you.",
            );
            $this->tellManagement($phone, "ما قدرت ألغي الطلب #{$ref}: {$why}");

            return;
        }

        $this->tellClient($chatId, $locale,
            "الإدارة وافقت على إلغاء الطلب #{$ref}، وانلغى.",
            "Management approved cancelling request #{$ref}. It is cancelled.",
        );
        $this->waitForNote($phone, $chatId, $locale, $ref);
        $this->tellManagement($phone, "انلغى الطلب #{$ref} وخبرت العميل.\nإذا بدك تبعتله ملاحظة اكتبها برسالة وحدة هلق.");
    }

    private function waitForNote(string $phone, string $chatId, string $locale, string $ref): void
    {
        Cache::put($this->noteKey($phone), [
            'chat_id' => $chatId,
            'locale' => $locale,
            'ref' => $ref,
        ], now()->addMinutes(self::NOTE_MINUTES));
    }

    private function tellClient(string $chatId, string $locale, string $arabic, string $english): void
    {
        try {
            $this->telegram->send($chatId, $locale === 'en' ? $english : $arabic);
        } catch (Throwable $exception) {
            Log::warning('Cancel decision to client failed.', ['error' => $exception->getMessage()]);
        }
    }

    private function tellManagement(string $phone, string $text): void
    {
        try {
            $this->whatsApp->sendText($this->digits($phone), $text);
        } catch (Throwable $exception) {
            Log::warning('Cancel reply to management failed.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * @return list<string>
     */
    private function numbers(): array
    {
        if (! $this->whatsApp->configured()) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($number): string => ClientPhone::whatsappDigits((string) $number),
            (array) config('services.whatsapp.management_numbers', []),
        ))));
    }

    private function approvalKey(string $token): string
    {
        return 'hoc:cancel-approval:'.$token;
    }

    private function pendingKey(int $requestId): string
    {
        return 'hoc:cancel-pending:'.$requestId;
    }

    private function noteKey(string $phone): string
    {
        return 'hoc:cancel-note:'.$this->digits($phone);
    }

    private function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
