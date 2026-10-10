<?php

namespace App\Support;

use App\Models\OpsSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class WorkCalendar
{
    public const HOURS_KEY = 'work_hours_per_day';

    public const HOLIDAYS_KEY = 'work_holidays';

    public const WHATSAPP_OPEN_KEY = 'whatsapp_bot_open';

    public const WHATSAPP_CLOSE_KEY = 'whatsapp_bot_close';

    public const TEAM_OPEN_KEY = 'team_work_open';

    public const TEAM_CLOSE_KEY = 'team_work_close';

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
        $list = $dates === [] ? '' : implode('، ', $dates);

        return $list;
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

    /**
     * @return array{open: string, close: string}
     */
    public function whatsappHours(): array
    {
        return [
            'open' => $this->clock(self::WHATSAPP_OPEN_KEY, '21:00'),
            'close' => $this->clock(self::WHATSAPP_CLOSE_KEY, '09:00'),
        ];
    }

    public function saveWhatsAppHours(string $open, string $close): void
    {
        OpsSetting::setValue(self::WHATSAPP_OPEN_KEY, $this->clockValue($open, '21:00'));
        OpsSetting::setValue(self::WHATSAPP_CLOSE_KEY, $this->clockValue($close, '09:00'));
    }

    public function isWhatsAppOpen(?CarbonInterface $moment = null): bool
    {
        $local = ($moment ?? Carbon::now('Asia/Damascus'))->copy()->timezone('Asia/Damascus');
        [$open, $close] = $this->windowMinutes();
        $minute = ($local->hour * 60) + $local->minute;

        if ($close > $open) {
            return $this->isWorkDay($local) && $minute >= $open && $minute < $close;
        }

        if ($minute >= $open) {
            return $this->isWorkDay($local);
        }

        if ($minute < $close) {
            return $this->isWorkDay($local->copy()->subDay());
        }

        return false;
    }

    public function nextWhatsAppOpen(?CarbonInterface $from = null): CarbonInterface
    {
        $local = ($from ?? Carbon::now('Asia/Damascus'))->copy()->timezone('Asia/Damascus');
        [$openHour, $openMinute] = array_map('intval', explode(':', $this->whatsappHours()['open']));
        $start = $local->copy()->setTime($openHour, $openMinute);
        if ($this->isWorkDay($local) && $local->lt($start)) {
            return $start;
        }

        return $this->nextWorkStart($local)->setTime($openHour, $openMinute);
    }

    /**
     * @return array{open: string, close: string}
     */
    public function teamHours(): array
    {
        return [
            'open' => $this->clock(self::TEAM_OPEN_KEY, '09:00'),
            'close' => $this->clock(self::TEAM_CLOSE_KEY, '21:00'),
        ];
    }

    public function saveTeamHours(string $open, string $close): void
    {
        OpsSetting::setValue(self::TEAM_OPEN_KEY, $this->clockValue($open, '09:00'));
        OpsSetting::setValue(self::TEAM_CLOSE_KEY, $this->clockValue($close, '21:00'));
    }

    /**
     * Daytime window for the team. An overnight pair falls back to 09:00–21:00.
     *
     * @return array{0: int, 1: int}
     */
    public function teamWindowMinutes(): array
    {
        $hours = $this->teamHours();
        $open = array_map('intval', explode(':', $hours['open']));
        $close = array_map('intval', explode(':', $hours['close']));
        $openMinute = ($open[0] * 60) + $open[1];
        $closeMinute = ($close[0] * 60) + $close[1];
        if ($closeMinute <= $openMinute) {
            return [9 * 60, 21 * 60];
        }

        return [$openMinute, $closeMinute];
    }

    public function whatsappClosedNotice(): string
    {
        return 'هلا مو موجودين عالواتساب، ابعتلنا بعدين ومنرد عليك.';
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

    private function clock(string $key, string $fallback): string
    {
        return $this->clockValue((string) OpsSetting::getValue($key, $fallback), $fallback);
    }

    private function clockValue(string $value, string $fallback): string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $fallback;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function windowMinutes(): array
    {
        $hours = $this->whatsappHours();
        $open = array_map('intval', explode(':', $hours['open']));
        $close = array_map('intval', explode(':', $hours['close']));
        return [($open[0] * 60) + $open[1], ($close[0] * 60) + $close[1]];
    }
}
