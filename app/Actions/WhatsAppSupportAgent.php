<?php

namespace App\Actions;

use App\Models\Client;
use App\Services\TelegramNotifier;
use App\Support\ClientChannelGate;
use App\Support\ClientProfileValue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When the WhatsApp bot does not understand twice in a row, or the client asks for a
 * person, the chat moves to the n8n support agent. A handoff from the agent, or an
 * agent that does not answer, brings in the support team.
 */
class WhatsAppSupportAgent
{
    public const MISSES_TO_HAND_OVER = 2;

    /** The chat goes back to the bot after this long without a client message. */
    private const IDLE_MINUTES = 30;

    private const TURNS_KEPT = 8;

    public function __construct(
        private ClientAssistant $assistant,
        private NotifyEmployees $notifyEmployees,
        private TelegramNotifier $telegram,
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
     * True when the agent handed the client to the team; the caller holds the bot.
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
            'رح يكمل معك مساعد الدعم هلق. إذا بدك ترجع للقائمة اكتب: رجوع للبوت',
            'Our support assistant will continue with you now. To go back to the menu, write: back to bot',
        ));

        return $this->relay($client, $chatId, $phone, $locale, $text);
    }

    /**
     * True when the agent handed the client to the team; the caller holds the bot.
     */
    public function relay(Client $client, string $chatId, string $phone, string $locale, string $text): bool
    {
        $state = Cache::get($this->key($phone));
        $state = is_array($state) ? $state : ['reason' => '', 'turns' => []];
        $turns = is_array($state['turns'] ?? null) ? $state['turns'] : [];
        $turns[] = ['role' => 'user', 'text' => mb_substr($text, 0, 1000)];

        $output = $this->ask($client, $phone, $locale, $text, (string) ($state['reason'] ?? ''));
        if ($output === null) {
            $this->handoff($client, $chatId, $phone, $locale, 'The support agent did not answer.', $turns, true);

            return true;
        }

        $this->say($chatId, $output['reply']);
        $turns[] = ['role' => 'assistant', 'text' => mb_substr($output['reply'], 0, 1000)];
        if ($output['handoff']) {
            $this->handoff($client, $chatId, $phone, $locale, $output['reason'] !== '' ? $output['reason'] : 'The support agent asked for the team.', $turns, false);

            return true;
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
        return preg_match('/رجوع\s*لل(?:بوت)|رجّع\s*البوت|^\s*(?:القائمة|قائمة|menu)\s*$|back to (?:the )?bot/ui', $text) === 1;
    }

    /**
     * @return array{reply: string, handoff: bool, reason: string}|null
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
        ];
    }

    /**
     * @param  list<array{role: string, text: string}>  $turns
     */
    private function handoff(Client $client, string $chatId, string $phone, string $locale, string $reason, array $turns, bool $tellClient): void
    {
        $this->end($phone);
        if ($tellClient) {
            $support = ClientChannelGate::SUPPORT_PHONE;
            $this->say($chatId, $this->tx(
                $locale,
                "حولتك لموظف من فريق الدعم، رح يرد عليك هون بأقرب وقت.\nإذا مستعجل احكي معنا على {$support}.",
                "A member of our support team will reply to you here shortly.\nIf it is urgent, call {$support}.",
            ));
        }

        $lines = [
            'عميل بحاجة لموظف دعم على واتساب',
            'العميل: '.($client->company_name ?: $client->name).' +'.$phone,
            'السبب: '.$reason,
            '',
            'آخر الرسائل:',
        ];
        foreach (array_slice($turns, -6) as $turn) {
            $lines[] = (($turn['role'] ?? '') === 'user' ? 'العميل: ' : 'المساعد: ').mb_substr((string) ($turn['text'] ?? ''), 0, 300);
        }
        $lines[] = '';
        $lines[] = 'رد عليه من رقم الشركة، والبوت متوقف على هالمحادثة ساعتين.';
        $this->notifyEmployees->support(implode("\n", $lines));
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
}
