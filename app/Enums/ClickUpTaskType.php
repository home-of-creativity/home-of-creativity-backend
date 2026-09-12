<?php

namespace App\Enums;

enum ClickUpTaskType: string
{
    case Sales = 'sales';
    case Design = 'design';
    case Content = 'content';
    case Programming = 'programming';
    case Revision = 'revision';

    public static function fromDepartment(string $department): ?self
    {
        $normalized = strtolower(trim($department));

        return match ($normalized) {
            'programming', 'web', 'development', 'dev', 'البرمجة', 'برمجة', 'ويب' => self::Programming,
            'design', 'تصميم', 'branding' => self::Design,
            'content', 'محتوى' => self::Content,
            'sales', 'المبيعات' => self::Sales,
            'revision' => self::Revision,
            default => self::tryFrom($normalized),
        };
    }
}
