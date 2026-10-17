<?php

namespace App\Actions;

use App\Models\BotFeedback;
use App\Models\Client;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After the support agent (or a staff hold) ends, ask how the bot felt. A low
 * score asks why and alerts management.
 */
class BotFeedbackSurvey
{
    /** Do not ask the same client again inside this window. */
    public const QUIET_DAYS = 7;

    /** @var array<string, array{0: string, 1: string}> */
    public const REASONS = [
        'slow' => ['بطء الرد', 'Slow replies'],
        'unclear' => ['ما فهمني', 'Did not understand me'],
        'wrong' => ['معلومة غلط', 'Wrong information'],
        'hard' => ['صعب الاستخدام', 'Hard to use'],
        'other' => ['سبب تاني', 'Something else'],
    ];

    public function __construct(
        private NotifyEmployees $notifyEmployees,
        private TelegramNotifier $telegram,
    ) {}

    /**
     * Opens a new rating when the client has not been asked lately. Returns true when the question was sent.
     */
    public function ask(Client $client, string $chatId, string $source, string $locale = 'ar'): bool
    {
        if (! in_array($source, [BotFeedback::SOURCE_SUPPORT_AGENT, BotFeedback::SOURCE_HUMAN_HOLD], true)) {
            return false;
        }
        if ($this->askedLately($client->id)) {
            return false;
        }

        $row = BotFeedback::query()->create([
            'client_id' => $client->id,
            'source' => $source,
            'channel' => Client::isWhatsAppKey($chatId) ? 'whatsapp' : 'telegram',
            'asked_at' => now(),
        ]);

        try {
            $this->telegram->sendInlineActions($chatId, $this->question($client, $locale), $this->scoreButtons($row, $locale));
        } catch (Throwable $exception) {
            Log::warning('Bot feedback ask failed.', [
                'client' => $client->id,
                'error' => $exception->getMessage(),
            ]);
            $row->delete();

            return false;
        }

        return true;
    }

    /**
     * Handles `botfb:{id}:{score}` and `botfbwhy:{id}:{reason}`.
     */
    public function answer(Client $client, string $chatId, string $data, string $locale = 'ar'): bool
    {
        if (preg_match('/^botfb:(\d+):([1-5])$/', $data, $score) === 1) {
            $row = $this->owned($client, (int) $score[1]);
            if ($row === null) {
                return false;
            }
            $this->score($client, $chatId, $row, (int) $score[2], $locale);

            return true;
        }
        if (preg_match('/^botfbwhy:(\d+):([a-z]+)$/', $data, $why) === 1 && isset(self::REASONS[$why[2]])) {
            $row = $this->owned($client, (int) $why[1]);
            if ($row === null) {
                return false;
            }
            $this->reason($client, $chatId, $row, $why[2], $locale);

            return true;
        }

        return false;
    }

    private function score(Client $client, string $chatId, BotFeedback $row, int $score, string $locale): void
    {
        if ($row->score !== null) {
            $this->reply($chatId, $this->tx($locale, 'سجّلنا تقييمك من قبل، شكراً إلك.', 'Your rating is already saved. Thank you.'));

            return;
        }
        $row->forceFill([
            'score' => $score,
            'answered_at' => now(),
            'channel' => Client::isWhatsAppKey($chatId) ? 'whatsapp' : 'telegram',
        ])->save();

        if (! $row->isLow()) {
            $this->reply($chatId, $this->tx(
                $locale,
                'شكراً كتير، تقييمك بيساعدنا نحسّن البوت.',
                'Thank you. Your rating helps us improve the bot.',
            ));

            return;
        }

        $buttons = [];
        foreach (self::REASONS as $key => [$arabic, $english]) {
            $buttons[] = ['text' => $this->tx($locale, $arabic, $english), 'callback_data' => "botfbwhy:{$row->id}:{$key}"];
        }
        $this->reply($chatId, $this->tx(
            $locale,
            'آسفين إنو التجربة ما كانت مريحة. شو أكتر شي ضايقك؟',
            'Sorry it was not comfortable. What bothered you most?',
        ), $buttons);
        $this->notifyEmployees->management("تقييم منخفض للبوت: {$score} من 5\n".$this->who($row)."\nبانتظار السبب من العميل.");
    }

    private function reason(Client $client, string $chatId, BotFeedback $row, string $key, string $locale): void
    {
        if (! $row->isLow() || $row->reason !== null) {
            $this->reply($chatId, $this->tx($locale, 'وصلتنا ملاحظتك، شكراً إلك.', 'We have your note. Thank you.'));

            return;
        }
        $row->forceFill(['reason' => $key])->save();
        $this->reply($chatId, $this->tx(
            $locale,
            'شكراً، وصلت ملاحظتك للإدارة ورح نشتغل على تحسين البوت.',
            'Thank you. Management has your note and we will work on improving the bot.',
        ));
        $this->notifyEmployees->management(
            'سبب تقييم البوت المنخفض ('.$row->score.' من 5): '.self::REASONS[$key][0]."\n".$this->who($row),
        );
    }

    private function owned(Client $client, int $id): ?BotFeedback
    {
        return BotFeedback::query()->whereKey($id)->where('client_id', $client->id)->first();
    }

    private function askedLately(int $clientId): bool
    {
        return BotFeedback::query()
            ->where('client_id', $clientId)
            ->where('asked_at', '>=', now()->subDays(self::QUIET_DAYS))
            ->exists();
    }

    private function question(Client $client, string $locale): string
    {
        $name = trim((string) $client->name);

        return $this->tx(
            $locale,
            'أهلاً'.($name !== '' ? ' '.$name : '').'، كيف كانت تجربتك مع مساعد الدعم؟ اختار من 1 لـ 5، و5 يعني ممتازة.',
            'Hi'.($name !== '' ? ' '.$name : '').', how was your experience with the support assistant? Pick 1 to 5, where 5 is excellent.',
        );
    }

    /**
     * @return list<array{text: string, callback_data: string}>
     */
    private function scoreButtons(BotFeedback $row, string $locale): array
    {
        $labels = [
            5 => ['5 ممتازة', '5 Excellent'],
            4 => ['4 منيحة كتير', '4 Very good'],
            3 => ['3 مقبولة', '3 Okay'],
            2 => ['2 ضعيفة', '2 Poor'],
            1 => ['1 سيئة', '1 Bad'],
        ];
        $buttons = [];
        foreach ($labels as $score => [$arabic, $english]) {
            $buttons[] = ['text' => $this->tx($locale, $arabic, $english), 'callback_data' => "botfb:{$row->id}:{$score}"];
        }

        return $buttons;
    }

    private function who(BotFeedback $row): string
    {
        $row->loadMissing('client');
        $client = $row->client;
        $source = $row->source === BotFeedback::SOURCE_HUMAN_HOLD ? 'بعد إسكات الموظف' : 'بعد مساعد الدعم';
        $lines = ['مصدر التقييم: '.$source];
        if ($client !== null) {
            $lines[] = 'العميل '.($client->company_name ?: $client->name).(filled($client->phone) ? ' '.$client->phone : '');
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $buttons
     */
    private function reply(string $chatId, string $text, array $buttons = []): void
    {
        try {
            if ($buttons === []) {
                $this->telegram->send($chatId, $text);
            } else {
                $this->telegram->sendInlineActions($chatId, $text, $buttons);
            }
        } catch (Throwable $exception) {
            Log::warning('Bot feedback reply failed.', ['error' => $exception->getMessage()]);
        }
    }

    private function tx(string $locale, string $arabic, string $english): string
    {
        return $locale === 'en' ? $english : $arabic;
    }
}
