<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\OpsFollowUp;
use App\Models\RequestRating;
use App\Models\RequestStatusHistory;
use App\Models\ServiceRequest;
use Illuminate\Support\Carbon;

/**
 * The finance page of the week that just ended, sent to management on WhatsApp.
 * Amounts follow the invoice date, as the finance page does.
 */
class WeeklyFinanceSummary
{
    private const TOP_OVERDUE = 3;

    public function __construct(
        private AdminBotDesk $desk,
        private NotifyEmployees $notifyEmployees,
    ) {}

    /**
     * Null when this week was already sent.
     */
    public function send(?Carbon $today = null): ?string
    {
        [$start, $end] = $this->lastWeek($today);
        if (! OpsFollowUp::claim(OpsFollowUp::KIND_FINANCE_WEEKLY, 'week:'.$start->toDateString())) {
            return null;
        }
        $text = $this->build($start, $end);
        $this->notifyEmployees->management($text);

        return $text;
    }

    public function build(Carbon $start, Carbon $end): string
    {
        $week = $this->desk->finance(['from' => $start->toDateString(), 'to' => $end->toDateString()]);
        $before = $this->desk->finance([
            'from' => $start->copy()->subWeek()->toDateString(),
            'to' => $end->copy()->subWeek()->toDateString(),
        ]);
        $all = $this->desk->finance([]);
        $now = $this->desk->finance(['invoice_state' => 'overdue', 'per_page' => 200]);
        $currency = (string) ($all['currencies'][0] ?? 'USD');

        $lines = [
            'الملخص المالي الأسبوعي',
            'من '.$start->format('j/n').' إلى '.$end->format('j/n'),
            '',
            'الفواتير الصادرة: '.$this->money($week['invoiced'], $currency).' (الأسبوع اللي قبله '.$this->money($before['invoiced'], $currency).')',
            'المحصّل منها: '.$this->money($week['revenue_paid'], $currency),
            'المصاريف: '.$this->money($week['expenses'], $currency),
            'الصافي: '.$this->money($week['net'], $currency).' (الأسبوع اللي قبله '.$this->money($before['net'], $currency).')',
            '',
            'المبالغ المفتوحة اليوم: '.$this->money($all['revenue_open'], $currency),
            'منها متأخرة: '.$this->money($now['revenue_overdue'], $currency),
        ];
        $late = $this->topOverdue($now['invoices']);
        if ($late !== []) {
            $lines[] = 'أكبر المتأخرين:';
            foreach ($late as $name => $amount) {
                $lines[] = '- '.$name.': '.$this->money($amount, $currency);
            }
        }

        $lines[] = '';
        $lines[] = 'الطلبات: '.$this->newRequests($start, $end).' جديدة، '.$this->completedRequests($start, $end).' مكتملة.';
        $lines[] = $this->ratings($start, $end);

        return implode("\n", $lines);
    }

    /**
     * Sunday to Saturday before the given day, Damascus time.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function lastWeek(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->setTimezone('Asia/Damascus')->startOfDay();
        $start = $today->copy()->subDays($today->dayOfWeek + 7);

        return [$start, $start->copy()->addDays(6)];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function topOverdue(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['partner_name'] ?? '')) ?: 'بدون اسم';
            $totals[$name] = ($totals[$name] ?? 0.0) + (float) ($row['residual'] ?? 0);
        }
        arsort($totals);

        return array_slice($totals, 0, self::TOP_OVERDUE, true);
    }

    private function newRequests(Carbon $start, Carbon $end): int
    {
        return ServiceRequest::query()
            ->whereBetween('created_at', $this->utcRange($start, $end))
            ->count();
    }

    private function completedRequests(Carbon $start, Carbon $end): int
    {
        return RequestStatusHistory::query()
            ->where('to_status', RequestStatus::Completed->value)
            ->whereBetween('created_at', $this->utcRange($start, $end))
            ->distinct()
            ->count('request_id');
    }

    private function ratings(Carbon $start, Carbon $end): string
    {
        $rows = RequestRating::query()
            ->whereNotNull('score')
            ->whereBetween('answered_at', $this->utcRange($start, $end))
            ->pluck('score');
        if ($rows->isEmpty()) {
            return 'رضا العملاء: ما في تقييمات هالأسبوع.';
        }
        $low = $rows->filter(fn ($score): bool => (int) $score <= RequestRating::LOW)->count();

        return 'رضا العملاء: '.number_format((float) $rows->avg(), 1).' من 5 ('.$rows->count().' تقييم'.($low > 0 ? '، منها '.$low.' منخفض' : '').').';
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function utcRange(Carbon $start, Carbon $end): array
    {
        return [
            $start->copy()->startOfDay()->setTimezone(config('app.timezone')),
            $end->copy()->endOfDay()->setTimezone(config('app.timezone')),
        ];
    }

    private function money(float|int $amount, string $currency): string
    {
        return number_format((float) $amount, 2).' '.$currency;
    }
}
