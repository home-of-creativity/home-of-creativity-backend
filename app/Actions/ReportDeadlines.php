<?php

namespace App\Actions;

use App\Models\ClientReport;
use App\Models\ReportDeadline;
use App\Models\ReportDeadlineOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Report due dates on the reports calendar. A repeating deadline is expanded per range;
 * a row in report_deadline_occurrences changes one date (done, skipped, or moved).
 */
class ReportDeadlines
{
    public const ZONE = 'Asia/Damascus';

    /** A moved date is still found when its original date is this far before the range. */
    private const MOVE_REACH_DAYS = 400;

    /** Publishing completes the earliest open date from this far back ... */
    private const COMPLETE_BACK_DAYS = 45;

    /** ... up to this far ahead. */
    private const COMPLETE_AHEAD_DAYS = 20;

    /**
     * @return list<array<string, mixed>>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to, ?int $clientId = null): array
    {
        $deadlines = ReportDeadline::query()
            ->with(['client', 'occurrences.report', 'occurrences.doneBy'])
            ->when($clientId, fn ($query) => $query->where('client_id', $clientId))
            ->whereDate('starts_on', '<=', $to)
            ->where(fn ($query) => $query->whereNull('repeat_until')->orWhereDate('repeat_until', '>=', $from->subDays(self::MOVE_REACH_DAYS)))
            ->get();

        $rows = [];
        foreach ($deadlines as $deadline) {
            foreach ($this->expand($deadline, $from, $to) as $row) {
                $rows[] = $row;
            }
        }
        usort($rows, fn (array $a, array $b): int => strcmp($a['date'].($a['time'] ?? ''), $b['date'].($b['time'] ?? '')));

        return $rows;
    }

    /**
     * Marks the earliest open date of this client's deadlines as answered by the report.
     * A report that already answered a date does not answer another one when republished.
     */
    public function completeFor(ClientReport $report, ?User $user): void
    {
        if (ReportDeadlineOccurrence::query()->where('client_report_id', $report->id)->whereNotNull('done_at')->exists()) {
            return;
        }
        $today = CarbonImmutable::now(self::ZONE)->startOfDay();
        $deadlines = ReportDeadline::query()->with('occurrences')->where('client_id', $report->client_id)->get();

        $open = collect();
        foreach ($deadlines as $deadline) {
            foreach ($this->expand($deadline, $today->subDays(self::COMPLETE_BACK_DAYS), $today->addDays(self::COMPLETE_AHEAD_DAYS)) as $row) {
                if ($row['status'] === 'overdue' || $row['status'] === 'upcoming') {
                    $open->push($row);
                }
            }
        }
        $first = $open->sortBy(fn (array $row): string => $row['date'].($row['time'] ?? ''))->first();
        if ($first === null) {
            return;
        }

        ReportDeadlineOccurrence::query()->updateOrCreate(
            ['report_deadline_id' => $first['deadline_id'], 'occurs_on' => $first['occurs_on']],
            ['done_at' => now(), 'done_by' => $user?->id, 'client_report_id' => $report->id, 'skipped' => false],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function expand(ReportDeadline $deadline, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fromDay = $from->setTimezone(self::ZONE)->format('Y-m-d');
        $toDay = $to->setTimezone(self::ZONE)->format('Y-m-d');
        /** @var Collection<string, ReportDeadlineOccurrence> $overrides */
        $overrides = $deadline->occurrences->keyBy(fn (ReportDeadlineOccurrence $row): string => self::day($row->occurs_on));

        $rows = [];
        foreach ($this->dates($deadline, CarbonImmutable::parse($fromDay)->subDays(self::MOVE_REACH_DAYS), CarbonImmutable::parse($toDay)) as $date) {
            $override = $overrides->get($date);
            $day = self::day($override?->moved_on) ?? $date;
            if ($day < $fromDay || $day >= $toDay) {
                continue;
            }
            $rows[] = $this->row($deadline, $date, $override);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function row(ReportDeadline $deadline, string $date, ?ReportDeadlineOccurrence $override): array
    {
        $moved = self::day($override?->moved_on);
        $day = $moved ?? $date;
        $time = self::clock($moved !== null ? $override?->moved_time : $deadline->due_time);
        $due = CarbonImmutable::parse($day.' '.($time ?? '23:59'), self::ZONE);

        $status = match (true) {
            $override?->done_at !== null => 'done',
            (bool) $override?->skipped => 'skipped',
            $due->isPast() => 'overdue',
            default => 'upcoming',
        };
        $client = $deadline->client;

        return [
            'key' => $deadline->id.':'.$date,
            'deadline_id' => $deadline->id,
            'occurs_on' => $date,
            'date' => $day,
            'time' => $time,
            'all_day' => $time === null,
            'start' => $time === null ? $day : $due->toIso8601String(),
            'moved' => $moved !== null,
            'title' => $deadline->title,
            'notes' => $deadline->notes,
            'color' => $deadline->color,
            'repeat' => $deadline->repeat,
            'repeat_until' => self::day($deadline->repeat_until),
            'starts_on' => self::day($deadline->starts_on),
            'due_time' => self::clock($deadline->due_time),
            'status' => $status,
            'done_at' => $override?->done_at?->toIso8601String(),
            'done_by' => $override?->doneBy?->name,
            'report' => $override?->report ? ['id' => $override->report->id, 'title' => $override->report->title] : null,
            'client' => $client ? ['id' => $client->id, 'name' => $client->name, 'company_name' => $client->company_name] : null,
        ];
    }

    /**
     * Original dates of the series inside [$from, $to).
     *
     * @return list<string>
     */
    public function dates(ReportDeadline $deadline, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = CarbonImmutable::parse((string) self::day($deadline->starts_on));
        $until = filled($deadline->repeat_until) ? CarbonImmutable::parse((string) self::day($deadline->repeat_until)) : null;

        if ($deadline->repeat === 'none') {
            return $start->lt($to) ? [$start->format('Y-m-d')] : [];
        }

        $dates = [];
        // Months are stepped from the first date so the 31st falls back to the 30th and returns.
        for ($index = 0; $index < 5000; $index++) {
            $date = match ($deadline->repeat) {
                'weekly' => $start->addWeeks($index),
                'quarterly' => $start->addMonthsNoOverflow($index * 3),
                'yearly' => $start->addYearsNoOverflow($index),
                default => $start->addMonthsNoOverflow($index),
            };
            if ($date->gte($to) || ($until && $date->gt($until))) {
                break;
            }
            if ($date->gte($from)) {
                $dates[] = $date->format('Y-m-d');
            }
        }

        return $dates;
    }

    /** "Y-m-d" from a date column, whatever the driver returned. */
    public static function day(mixed $value): ?string
    {
        return filled($value) ? substr((string) $value, 0, 10) : null;
    }

    /** "H:i" from a time column. */
    public static function clock(mixed $value): ?string
    {
        return filled($value) ? substr((string) $value, 0, 5) : null;
    }
}
