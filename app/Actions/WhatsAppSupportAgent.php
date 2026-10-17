<?php

namespace App\Actions;

use App\Contracts\WhatsAppMessenger;
use App\Models\Client;
use App\Services\TelegramNotifier;
use App\Support\ClientChannelGate;
use App\Support\ClientPhone;
use App\Support\ClientProfileValue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When the WhatsApp bot does not understand twice in a row, or the client asks for a
 * person, the chat moves to the n8n support agent. A handoff alerts staff while the
 * agent keeps talking until a staff number writes that they are ready.
 */
class WhatsAppSupportAgent
{
    public const MISSES_TO_HAND_OVER = 2;

    /** The chat goes back to the bot after this long without a client message. */
    private const IDLE_MINUTES = 30;

    private const TURNS_KEPT = 8;

    /** How long staff can still claim the chat after the first alert. */
    private const STAFF_WAIT_HOURS = 2;

    public function __construct(
        private ClientAssistant $assistant,
        private NotifyEmployees $notifyEmployees,
        private TelegramNotifier $telegram,
        private SupportAgentActions $actions,
        private WhatsAppMessenger $whatsApp,
    ) {}

    public function configured(): bool
    {
        return filled(config('services.n8n.support_agent_url'));
    }

    public function active(string $phone): bool
    {
        return Cache::has($this->key($phone));
    }

    public static function wantsHuman(string $text): bool
    {
        return preg_match(
            '/(?:بدي|بدنا|ابغى|أبغى|اريد|أريد|ممكن|خليني|حولني|حوّلني|وصلني)\s+(?:\S+\s+){0,3}?(?:موظف|حدا\s*من\s*الفريق|(?:إ|ا)نسان|الدعم|خدمة\s*(?:ال)?عملاء)|(?:موظف|شخص)\s*حقيقي|خدمة\s*(?:ال)?عملاء|\b(?:human|real person|customer service|support team|talk to (?:a|an|someone))\b/ui',
            $text,
        ) === 1;
    }

    /**
     * A bot reply that did not help: an escalation or the "which part was unclear" question.
     *
     * @param  array{action: string, answer: string}  $decision
     */
    public static function missed(array $decision): bool
    {
        return $decision['action'] === 'escalate'
            || in_array(trim($decision['answer']), [ClientProfileValue::UNCLEAR_QUESTION_AR, ClientProfileValue::UNCLEAR_QUESTION_EN], true);
    }

    /**
     * Staff wrote that they are ready to take the client. True when this message was handled.
     */
    public function handleStaffReady(string $staffPhone, ?string $text): bool
    {
        if (! $this->isStaff($staffPhone)) {
            return false;
        }
        $note = trim((string) $text);
        if ($note === '' || ! $this->saysReady($note)) {
            return false;
        }

        $pending = $this->openHandoffFor($staffPhone);
        if (! is_array($pending)) {
            $this->tellStaff($staffPhone, 'ما في محادثة بانتظارك هلق.');

            return true;
        }

        $clientPhone = Client::normalizeWhatsAppPhone((string) ($pending['phone'] ?? ''));
        $chatId = (string) ($pending['chat_id'] ?? '');
        $locale = (string) ($pending['locale'] ?? 'ar');
        if ($clientPhone === '' || $chatId === '') {
            $this->forgetOpenHandoff($clientPhone);

            return true;
        }

        $this->end($clientPhone);
        $this->forgetOpenHandoff($clientPhone);
        Cache::put($this->humanKey($clientPhone), 1, now()->addHours(self::STAFF_WAIT_HOURS));

        $this->say($chatId, $this->tx(
            $locale,
            'موظف من الفريق جاهز ويكمل معك هون.',
            'A team member is ready and will continue with you here.',
        ));
        $this->tellStaff($staffPhone, 'تمام، البوت وقف على هالمحادثة ساعتين. رد على العميل من رقم الشركة.');

        return true;
    }

    /**
     * True when the agent handed the client to the team; the caller holds the bot.
     * Soft handoffs (staff alerted, agent keeps talking) return false.
     *
     * @param  list<array{role: string, text: string}>  $history
     */
    public function start(Client $client, string $chatId, string $phone, string $locale, string $text, string $reason, array $history = []): bool
    {
        Cache::put($this->key($phone), [
            'reason' => $reason,
            'turns' => array_slice($history, -self::TURNS_KEPT),
        ], now()->addMinutes(self::IDLE_MINUTES));
        $this->say($chatId, $this->tx(
            $locale,
            'رح يكمل معك مساعد الدعم هلق. إذا بدك ترجع للقائمة اكتب: القائمة',
            'Our support assistant will continue with you now. To go back to the menu, write: menu',
        ));

        return $this->relay($client, $chatId, $phone, $locale, $text);
    }

    /**
     * Soft handoffs keep the agent. Only a hard failure that already held the chat returns true.
     */
    public function relay(Client $client, string $chatId, string $phone, string $locale, string $text): bool
    {
        $state = Cache::get($this->key($phone));
        $state = is_array($state) ? $state : ['reason' => '', 'turns' => []];
        $turns = is_array($state['turns'] ?? null) ? $state['turns'] : [];
        $turns[] = ['role' => 'user', 'text' => mb_substr($text, 0, 1000)];

        $output = $this->ask($client, $phone, $locale, $text, (string) ($state['reason'] ?? ''));
        if ($output === null) {
            $this->alertStaff($client, $chatId, $phone, $locale, 'The support agent did not answer.', $turns);
            $support = ClientChannelGate::SUPPORT_PHONE;
            $this->say($chatId, $this->tx(
                $locale,
                "خبرت فريق الدعم، ورح كمل معك هون لحد ما يجهز موظف.\nإذا مستعجل احكي معنا على {$support}.",
                "I told the support team, and I will keep helping here until someone is ready.\nIf it is urgent, call {$support}.",
            ));
            Cache::put($this->key($phone), [
                'reason' => $state['reason'] ?? '',
                'turns' => array_slice($turns, -self::TURNS_KEPT),
            ], now()->addMinutes(self::IDLE_MINUTES));

            return false;
        }

        $this->say($chatId, $output['reply']);
        $turns[] = ['role' => 'assistant', 'text' => mb_substr($output['reply'], 0, 1000)];
        $done = $output['action'] !== [] ? $this->actions->run($client, $chatId, $locale, $output['action'], $phone) : null;
        if ($done !== null) {
            $this->say($chatId, $done);
            $turns[] = ['role' => 'assistant', 'text' => mb_substr($done, 0, 1000)];
        }
        if ($output['handoff']) {
            $this->alertStaff(
                $client,
                $chatId,
                $phone,
                $locale,
                $output['reason'] !== '' ? $output['reason'] : 'The support agent asked for the team.',
                $turns,
            );
        }
        Cache::put($this->key($phone), [
            'reason' => $state['reason'] ?? '',
            'turns' => array_slice($turns, -self::TURNS_KEPT),
        ], now()->addMinutes(self::IDLE_MINUTES));

        return false;
    }

    public function end(string $phone): void
    {
        Cache::forget($this->key($phone));
    }

    public static function leaving(string $text): bool
    {
        return preg_match('/^\s*(?:القائمة|قائمة|menu)\s*$|رجوع\s*لل(?:بوت)|رجّع\s*البوت|back to (?:the )?bot/ui', $text) === 1;
    }

    /**
     * @return array{reply: string, handoff: bool, reason: string, action: array<string, mixed>}|null
     */
    private function ask(Client $client, string $phone, string $locale, string $text, string $reason): ?array
    {
        try {
            $response = Http::timeout(45)
                ->connectTimeout(5)
                ->acceptJson()
                ->withHeaders(['X-N8N-Secret' => (string) config('services.n8n.webhook_secret')])
                ->post((string) config('services.n8n.support_agent_url'), [
                    'session_id' => 'hoc-'.substr(hash('sha256', 'support-agent:'.$phone), 0, 32),
                    'locale' => $locale === 'en' ? 'en' : 'ar',
                    'client_name' => (string) $client->name,
                    'records' => $this->assistant->records($client),
                    'packages' => $this->assistant->packages(),
                    'reason' => $reason,
                    'support_phone' => ClientChannelGate::SUPPORT_PHONE,
                    'message' => mb_substr($text, 0, 2000),
                ]);
        } catch (Throwable $exception) {
            Log::warning('Support agent call failed.', ['error' => $exception->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            Log::warning('Support agent answered with an error.', ['status' => $response->status()]);

            return null;
        }
        $output = $response->json('output');
        $output = is_array($output) ? $output : $response->json();
        $reply = trim((string) ($output['reply'] ?? ''));
        if ($reply === '') {
            return null;
        }

        return [
            'reply' => mb_substr($reply, 0, 1500),
            'handoff' => filter_var($output['handoff'] ?? false, FILTER_VALIDATE_BOOL),
            'reason' => trim((string) ($output['handoff_reason'] ?? '')),
            'action' => is_array($output['action'] ?? null) ? $output['action'] : [],
        ];
    }

    /**
     * Alerts staff once per open handoff. The agent keeps talking until someone writes جاهز.
     *
     * @param  list<array{role: string, text: string}>  $turns
     */
    private function alertStaff(Client $client, string $chatId, string $phone, string $locale, string $reason, array $turns): void
    {
        $phone = Client::normalizeWhatsAppPhone($phone);
        if ($phone === '') {
            return;
        }
        if (! Cache::add($this->staffAlertKey($phone), 1, now()->addHours(self::STAFF_WAIT_HOURS))) {
            return;
        }

        $payload = [
            'phone' => $phone,
            'chat_id' => $chatId,
            'locale' => $locale === 'en' ? 'en' : 'ar',
            'client' => ($client->company_name ?: $client->name).' +'.$phone,
            'at' => now()->timestamp,
        ];
        $this->rememberOpenHandoff($phone, $payload);

        $lines = [
            'عميل بحاجة لموظف على واتساب',
            'العميل: '.($client->company_name ?: $client->name).' +'.$phone,
            'السبب: '.$reason,
            '',
            'آخر الرسائل:',
        ];
        foreach (array_slice($turns, -6) as $turn) {
            $lines[] = (($turn['role'] ?? '') === 'user' ? 'العميل: ' : 'المساعد: ').mb_substr((string) ($turn['text'] ?? ''), 0, 300);
        }
        $lines[] = '';
        $lines[] = 'المساعد لسا عم يرد على الزبون.';
        $lines[] = 'لما تكون جاهز ترد عليه، ابعت هون كلمة: جاهز';
        $this->notifyEmployees->support(implode("\n", $lines));
    }

    public static function saysReady(string $text): bool
    {
        $folded = mb_strtolower(trim($text));
        $folded = preg_replace('/\s+/u', ' ', $folded) ?? $folded;

        return preg_match(
            '/^(?:جاهز(?:\s+لل(?:رد|رد\s*عليه|محادثة|الزبون))?|انا\s+جاهز|أنا\s+جاهز|تسلمت|استلمت|بكمل\s+انا|بكمل\s+أنا|رح\s+اكمل|رح\s+أكمل|i\s*(?:am\s+)?ready|ready(?:\s+to\s+(?:reply|take\s+over))?|taking\s+over)\.?$/ui',
            $folded,
        ) === 1;
    }

    private function isStaff(string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits !== '' && in_array($digits, $this->staffNumbers(), true);
    }

    /**
     * @return list<string>
     */
    private function staffNumbers(): array
    {
        $support = (array) config('services.whatsapp.support_numbers', []);
        $numbers = $support !== [] ? $support : (array) config('services.whatsapp.management_numbers', []);

        return array_values(array_unique(array_filter(array_map(
            function ($number): string {
                $digits = ClientPhone::whatsappDigits((string) $number);
                if ($digits !== '') {
                    return $digits;
                }

                return preg_replace('/\D+/', '', (string) $number) ?? '';
            },
            $numbers,
        ))));
    }

    /**
     * @param  array{phone: string, chat_id: string, locale: string, client: string, at: int}  $payload
     */
    private function rememberOpenHandoff(string $clientPhone, array $payload): void
    {
        Cache::put($this->staffOpenKey($clientPhone), $payload, now()->addHours(self::STAFF_WAIT_HOURS));
        $opens = Cache::get($this->staffOpensKey());
        $opens = is_array($opens) ? $opens : [];
        $opens[$clientPhone] = $payload;
        Cache::put($this->staffOpensKey(), $opens, now()->addHours(self::STAFF_WAIT_HOURS));
        foreach ($this->staffNumbers() as $number) {
            Cache::put($this->staffPendingKey($number), $payload, now()->addHours(self::STAFF_WAIT_HOURS));
        }
    }

    /**
     * @return array{phone?: string, chat_id?: string, locale?: string, client?: string, at?: int}|null
     */
    private function openHandoffFor(string $staffPhone): ?array
    {
        $pending = Cache::get($this->staffPendingKey($staffPhone));
        if (is_array($pending)) {
            return $pending;
        }
        $opens = Cache::get($this->staffOpensKey());
        if (! is_array($opens) || $opens === []) {
            return null;
        }
        uasort($opens, fn (array $a, array $b): int => (int) ($b['at'] ?? 0) <=> (int) ($a['at'] ?? 0));

        return array_values($opens)[0] ?? null;
    }

    private function forgetOpenHandoff(string $clientPhone): void
    {
        if ($clientPhone !== '') {
            Cache::forget($this->staffAlertKey($clientPhone));
            Cache::forget($this->staffOpenKey($clientPhone));
        }
        $opens = Cache::get($this->staffOpensKey());
        if (is_array($opens)) {
            unset($opens[$clientPhone]);
            Cache::put($this->staffOpensKey(), $opens, now()->addHours(self::STAFF_WAIT_HOURS));
        }
        foreach ($this->staffNumbers() as $number) {
            $pending = Cache::get($this->staffPendingKey($number));
            if (is_array($pending) && (string) ($pending['phone'] ?? '') === $clientPhone) {
                Cache::forget($this->staffPendingKey($number));
            }
        }
    }

    private function tellStaff(string $phone, string $text): void
    {
        try {
            $digits = ClientPhone::whatsappDigits($phone);
            if ($digits === '') {
                $digits = preg_replace('/\D+/', '', $phone) ?? '';
            }
            if ($digits === '') {
                return;
            }
            $this->whatsApp->sendText($digits, $text);
        } catch (Throwable $exception) {
            Log::warning('Staff ready reply failed.', ['error' => $exception->getMessage()]);
        }
    }

    private function say(string $chatId, string $text): void
    {
        try {
            $this->telegram->send($chatId, $text);
        } catch (Throwable $exception) {
            Log::warning('Support agent reply failed.', ['error' => $exception->getMessage()]);
        }
    }

    private function tx(string $locale, string $arabic, string $english): string
    {
        return $locale === 'en' ? $english : $arabic;
    }

    private function key(string $phone): string
    {
        return 'hoc:wa-agent:'.$phone;
    }

    private function humanKey(string $phone): string
    {
        return 'hoc:wa-human:'.$phone;
    }

    private function staffAlertKey(string $phone): string
    {
        return 'hoc:wa-staff-alert:'.$phone;
    }

    private function staffOpenKey(string $phone): string
    {
        return 'hoc:wa-staff-open:'.$phone;
    }

    private function staffPendingKey(string $staffPhone): string
    {
        $digits = ClientPhone::whatsappDigits($staffPhone);
        if ($digits === '') {
            $digits = preg_replace('/\D+/', '', $staffPhone) ?? '';
        }

        return 'hoc:wa-staff-pending:'.$digits;
    }

    private function staffOpensKey(): string
    {
        return 'hoc:wa-staff-opens';
    }
}
