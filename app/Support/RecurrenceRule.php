<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Edits the RRULE lines of a Google Calendar series. Other lines (EXDATE, RDATE)
 * pass through untouched.
 */
final class RecurrenceRule
{
    private const DAYS = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

    /** @param  list<string>  $lines */
    public static function count(array $lines): ?int
    {
        foreach ($lines as $line) {
            $parts = self::parts($line);
            if ($parts !== null && isset($parts['COUNT'])) {
                return (int) $parts['COUNT'];
            }
        }

        return null;
    }

    /**
     * Ends the series before the occurrence that starts at $before.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function until(array $lines, CarbonInterface $before, bool $allDay): array
    {
        $until = $allDay
            ? $before->copy()->subDay()->format('Ymd')
            : $before->copy()->utc()->subSecond()->format('Ymd\THis\Z');

        return self::map($lines, function (array $parts) use ($until): array {
            unset($parts['COUNT']);
            $parts['UNTIL'] = $until;

            return $parts;
        });
    }

    /**
     * Sets COUNT, or removes it when $count is null.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function withCount(array $lines, ?int $count): array
    {
        return self::map($lines, function (array $parts) use ($count): array {
            unset($parts['COUNT']);
            if ($count !== null) {
                $parts['COUNT'] = (string) max(1, $count);
            }

            return $parts;
        });
    }

    /**
     * Moves weekday rules by whole days, so a weekly Monday series dragged to Tuesday
     * repeats on Tuesday.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function shiftDays(array $lines, int $days): array
    {
        if ($days % 7 === 0) {
            return $lines;
        }

        return self::map($lines, function (array $parts) use ($days): array {
            if (! isset($parts['BYDAY'])) {
                return $parts;
            }
            $parts['BYDAY'] = implode(',', array_map(function (string $code) use ($days): string {
                if (! preg_match('/^([+-]?\d*)(SU|MO|TU|WE|TH|FR|SA)$/', $code, $match)) {
                    return $code;
                }
                $index = array_search($match[2], self::DAYS, true);

                return $match[1].self::DAYS[(((int) $index + $days) % 7 + 7) % 7];
            }, explode(',', $parts['BYDAY'])));

            return $parts;
        });
    }

    /**
     * @param  list<string>  $lines
     * @param  callable(array<string, string>): array<string, string>  $change
     * @return list<string>
     */
    private static function map(array $lines, callable $change): array
    {
        return array_values(array_map(function (string $line) use ($change): string {
            $parts = self::parts($line);
            if ($parts === null) {
                return $line;
            }
            $parts = $change($parts);

            return 'RRULE:'.implode(';', array_map(
                fn (string $key, string $value): string => $key.'='.$value,
                array_keys($parts),
                $parts,
            ));
        }, $lines));
    }

    /** @return array<string, string>|null */
    private static function parts(string $line): ?array
    {
        if (! str_starts_with(strtoupper($line), 'RRULE:')) {
            return null;
        }
        $parts = [];
        foreach (explode(';', substr($line, 6)) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($key !== '') {
                $parts[strtoupper($key)] = $value;
            }
        }

        return $parts;
    }
}
