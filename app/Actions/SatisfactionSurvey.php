<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\RequestRating;
use App\Models\RequestStatusHistory;
use App\Models\ServiceRequest;
use App\Services\TelegramNotifier;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One WhatsApp question after a project is completed. A low score asks for the reason
 * and alerts management.
 */
class SatisfactionSurvey
{
    /** The delivery messages land before the question. */
    public const DELAY_HOURS = 2;

    /** Older completions are never asked, so a deploy does not survey the backlog. */
    public const LOOKBACK_DAYS = 3;

    /** A client finishing several projects is asked once in this window. */
    public const QUIET_DAYS = 7;

    /** @var array<string, array{0: string, 1: string}> */
    public const REASONS = [
        'time' => ['التأخير بالتسليم', 'Late delivery'],
        'quality' => ['جودة الشغل', 'Quality of work'],
        'contact' => ['التواصل والمتابعة', 'Communication'],
        'price' => ['السعر', 'Price'],
        'other' => ['سبب تاني', 'Something else'],
    ];

    public function __construct(
        private NotifyClientChannels $channels,
        private NotifyEmployees $notifyEmployees,
        private TelegramNotifier $telegram,
    ) {}

    public function sendDue(): int
    {
        $hour = now('Asia/Damascus')->hour;
        if ($hour < 10 || $hour >= 20) {
            return 0;
        }
        $ids = RequestStatusHistory::query()
            ->where('to_status', RequestStatus::Completed->value)
            ->whereBetween('created_at', [now()->subDays(self::LOOKBACK_DAYS), now()->subHours(self::DELAY_HOURS)])
            ->pluck('request_id')
            ->unique()
            ->all();
        if ($ids === []) {
            return 0;
        }
        $requests = ServiceRequest::query()
            ->with('client')
            ->whereIn('id', $ids)
            ->where('status', RequestStatus::Completed->value)
            ->whereNotNull('client_id')
            ->whereDoesntHave('rating')
            ->orderBy('id')
            ->get();

        $sent = 0;
        foreach ($requests as $request) {
            $rating = RequestRating::query()->firstOrCreate(
                ['request_id' => $request->id],
                ['client_id' => $request->client_id],
            );
            if (! $rating->wasRecentlyCreated || $this->askedLately($rating)) {
                continue;
            }
            $chatId = $this->channels->chatId($request);
            if ($chatId === null || ! Client::isWhatsAppKey($chatId)) {
                continue;
            }
            try {
                $this->telegram->sendInlineActions($chatId, $this->question($request), $this->scoreButtons($rating, $request->client));
                $rating->forceFill(['asked_at' => now(), 'channel' => 'whatsapp'])->save();
                $sent++;
            } catch (Throwable $exception) {
                Log::warning('Satisfaction survey send failed.', [
                    'request' => $request->number,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Handles `rate:{id}:{score}` and `ratewhy:{id}:{reason}` from the client's own chat.
     * False when the data is not a survey answer for this client.
     */
    public function answer(Client $client, string $chatId, string $data): bool
    {
        if (preg_match('/^rate:(\d+):([1-5])$/', $data, $score) === 1) {
            $rating = $this->owned($client, (int) $score[1]);
            if ($rating === null) {
                return false;
            }
            $this->score($client, $chatId, $rating, (int) $score[2]);

            return true;
        }
        if (preg_match('/^ratewhy:(\d+):([a-z]+)$/', $data, $why) === 1 && isset(self::REASONS[$why[2]])) {
            $rating = $this->owned($client, (int) $why[1]);
            if ($rating === null) {
                return false;
            }
            $this->reason($client, $chatId, $rating, $why[2]);

            return true;
        }

        return false;
    }

    private function score(Client $client, string $chatId, RequestRating $rating, int $score): void
    {
        if ($rating->score !== null) {
            $this->reply($chatId, $this->tx($client, 'سجّلنا تقييمك من قبل، شكراً إلك.', 'Your rating is already saved. Thank you.'));

            return;
        }
        $rating->forceFill(['score' => $score, 'answered_at' => now(), 'channel' => 'whatsapp'])->save();
        if (! $rating->isLow()) {
            $this->reply($chatId, $this->tx(
                $client,
                'شكراً كتير، تقييمك بيفرحنا. إذا احتجت أي شي جديد نحنا موجودين.',
                'Thank you, that means a lot. We are here whenever you need something new.',
            ));

            return;
        }

        $buttons = [];
        foreach (self::REASONS as $key => [$arabic, $english]) {
            $buttons[] = ['text' => $this->tx($client, $arabic, $english), 'callback_data' => "ratewhy:{$rating->id}:{$key}"];
        }
        $this->reply($chatId, $this->tx(
            $client,
            'آسفين إنو التجربة ما كانت متل ما بتستاهل. شو أكتر شي ضايقك؟',
            'We are sorry it fell short. What bothered you most?',
        ), $buttons);
        $this->notifyEmployees->management("تقييم منخفض: {$score} من 5\n".$this->who($rating)."\nبانتظار السبب من العميل.");
    }

    private function reason(Client $client, string $chatId, RequestRating $rating, string $key): void
    {
        if (! $rating->isLow() || $rating->reason !== null) {
            $this->reply($chatId, $this->tx($client, 'وصلتنا ملاحظتك، شكراً إلك.', 'We have your note. Thank you.'));

            return;
        }
        $rating->forceFill(['reason' => $key])->save();
        $this->reply($chatId, $this->tx(
            $client,
            'شكراً، وصلت ملاحظتك للإدارة ورح يتواصل معك حدا من الفريق قريباً.',
            'Thank you. Management has your note and someone from the team will contact you soon.',
        ));
        $this->notifyEmployees->management('سبب التقييم المنخفض ('.$rating->score.' من 5): '.self::REASONS[$key][0]."\n".$this->who($rating));
    }

    private function owned(Client $client, int $id): ?RequestRating
    {
        return RequestRating::query()->whereKey($id)->where('client_id', $client->id)->first();
    }

    private function askedLately(RequestRating $rating): bool
    {
        return RequestRating::query()
            ->where('client_id', $rating->client_id)
            ->whereKeyNot($rating->id)
            ->where('asked_at', '>=', now()->subDays(self::QUIET_DAYS))
            ->exists();
    }

    private function question(ServiceRequest $request): string
    {
        $ref = ResolveServiceRequest::displayNumber($request);
        $name = trim((string) $request->client?->name);

        return $this->tx(
            $request->client,
            'أهلاً'.($name !== '' ? ' '.$name : '').'، خلص طلبك #'.$ref.'. كيف كانت تجربتك معنا؟ اختار رقم من 1 لـ 5، و5 يعني ممتازة.',
            'Hi'.($name !== '' ? ' '.$name : '').', your request #'.$ref.' is complete. How was your experience? Pick 1 to 5, where 5 is excellent.',
        );
    }

    /**
     * @return list<array{text: string, callback_data: string}>
     */
    private function scoreButtons(RequestRating $rating, ?Client $client): array
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
            $buttons[] = ['text' => $this->tx($client, $arabic, $english), 'callback_data' => "rate:{$rating->id}:{$score}"];
        }

        return $buttons;
    }

    private function who(RequestRating $rating): string
    {
        $rating->loadMissing('request.client');
        $request = $rating->request;
        $client = $request?->client;
        $lines = ['الطلب #'.($request ? ResolveServiceRequest::displayNumber($request) : $rating->request_id).($request?->title ? '، '.$request->title : '')];
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
            Log::warning('Satisfaction survey reply failed.', ['error' => $exception->getMessage()]);
        }
    }

    private function tx(?Client $client, string $arabic, string $english): string
    {
        return $client?->locale === 'en' ? $english : $arabic;
    }
}
