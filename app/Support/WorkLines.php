<?php

namespace App\Support;

use App\Models\ServiceRequest;

class WorkLines
{
    /** @var list<string> */
    public const DEPARTMENTS = ['design', 'content', 'programming', 'photography'];

    /**
     * @return list<array{department: string, hours: int, brief: string, priority: int}>
     */
    public static function normalize(mixed $lines): array
    {
        if (! is_array($lines)) {
            return [];
        }

        $clean = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $department = strtolower(trim((string) ($line['department'] ?? '')));
            $hours = (int) ($line['hours'] ?? 0);
            if (! in_array($department, self::DEPARTMENTS, true) || $hours < 1) {
                continue;
            }
            $clean[] = [
                'department' => $department,
                'hours' => $hours,
                'brief' => trim((string) ($line['brief'] ?? '')) ?: 'تنفيذ العمل',
                'priority' => 3,
            ];
        }

        return $clean;
    }

    /**
     * @return list<array{department: string, hours: int, brief: string, priority: int}>
     */
    public static function fromRequest(ServiceRequest $request): array
    {
        $request->loadMissing('pricingPackage');
        $packageLines = self::normalize($request->pricingPackage?->work_lines);
        if ($packageLines !== []) {
            return $packageLines;
        }

        return self::normalize($request->draft_work_lines);
    }

    /**
     * @param  list<string>  $rows
     * @return list<array{department: string, hours: int}>
     */
    public static function fromText(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $row) {
            $row = trim($row);
            if ($row === '') {
                continue;
            }
            if (preg_match('/^(design|content|programming|photography)\s+(\d+)$/i', $row, $match) !== 1) {
                continue;
            }
            $lines[] = [
                'department' => strtolower($match[1]),
                'hours' => (int) $match[2],
            ];
        }

        return self::normalize($lines);
    }
}
