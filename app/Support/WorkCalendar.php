<?php

namespace App\Support;

use App\Models\OpsSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class WorkCalendar
{
    public const HOURS_KEY = 'work_hours_per_day';

    public const HOLIDAYS_KEY = 'work_holidays';

    public const DAY_START_HOUR = 9;

    /** @return list<int> */
    public function workDays(): array
    {
        return [CarbonInterface::SATURDAY, CarbonInterface::SUNDAY, CarbonInterface::MONDAY, CarbonInterface::TUESDAY, CarbonInterface::WEDNESDAY, CarbonInterface::THURSDAY];
    }

    public function hoursPerDay(): int
    {
        $value = (int) OpsSetting::getValue(self::HOURS_KEY, '8');

        return $value > 0 ? $value : 8;
    }

    public function holidaySummary(): string
    {
        $dates = $this->holidays();
        $list = $dates === []
            ? 'لا توجد عطل إضافية محفوظة.'
            : 'العطل المحفوظة: '.implode('، ', $dates).'.';

        return "الجمعة عطلة.\nالتصوير لا يُحجز في نفس اليوم. موعدان في اليوم نفسه يفصل بينهما 5 ساعات، والموظف يقترح وقتاً حتى يوافق العميل.\n{$list}";
    }

    /** @return list<string> */
    public function holidays(): array
    {
        $raw = OpsSetting::getValue(self::HOLIDAYS_KEY, '[]');
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @param  list<string>  $dates */
    public function saveHolidays(array $dates): void
    {
        $clean = [];
        foreach ($dates as $date) {
            $trimmed = trim($date);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
                $clean[] = $trimmed;
            }
        }
        OpsSetting::setValue(self::HOLIDAYS_KEY, json_encode(array_values(array_unique($clean))));
    }

    public function saveHoursPerDay(int $hours): void
    {
        OpsSetting::setValue(self::HOURS_KEY, (string) max(1, $hours));
    }

    public function isWorkDay(CarbonInterface $day): bool
    {
        $local = $day->copy()->timezone('Asia/Damascus');
        if (in_array($local->toDateString(), $this->holidays(), true)) {
            return false;
        }

        return in_array($local->dayOfWeek, $this->workDays(), true);
    }

    public function addWorkHours(CarbonInterface $from, int $hours): CarbonInterface
    {
        $cursor = $from->copy()->timezone('Asia/Damascus');
        $left = max(0, $hours);
        if (! $this->isWorkDay($cursor)) {
            $cursor = $this->nextWorkStart($cursor);
        }

        $perDay = $this->hoursPerDay();
        while ($left > 0) {
            if (! $this->isWorkDay($cursor)) {
                $cursor = $this->nextWorkStart($cursor);

                continue;
            }

            $step = min($perDay, $left);
            $cursor = $cursor->addHours($step);
            $left -= $step;
            if ($left > 0) {
                $cursor = $this->nextWorkStart($cursor);
            }
        }

        return $cursor;
    }

    public function parallelHours(array $operations): int
    {
        $max = 0;
        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }
            $max = max($max, (int) ($operation['hours'] ?? 0));
        }

        return $max;
    }

    public function nextWorkStart(CarbonInterface $from): CarbonInterface
    {
        $cursor = $from->copy()->timezone('Asia/Damascus')->addDay()->setTime(self::DAY_START_HOUR, 0);
        for ($i = 0; $i < 21 && ! $this->isWorkDay($cursor); $i++) {
            $cursor->addDay();
        }

        return $cursor;
    }

    /** @return list<CarbonInterface> */
    public function upcomingWorkDays(int $count): array
    {
        $days = [];
        $cursor = Carbon::now('Asia/Damascus')->startOfDay();
        for ($i = 0; count($days) < $count && $i < 40; $i++) {
            if ($this->isWorkDay($cursor)) {
                $days[] = $cursor->copy();
            }
            $cursor->addDay();
        }

        return $days;
    }
}
