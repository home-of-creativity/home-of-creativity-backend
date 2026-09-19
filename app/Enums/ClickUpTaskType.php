<?php

namespace App\Enums;

enum ClickUpTaskType: string
{
    case Sales = 'sales';
    case Design = 'design';
    case Content = 'content';
    case Programming = 'programming';
    case Photography = 'photography';
    case Revision = 'revision';

    public static function fromDepartment(string $department): ?self
    {
        $normalized = strtolower(trim($department));

        return match ($normalized) {
            'programming', 'web', 'development', 'dev', 'البرمجة', 'برمجة', 'ويب' => self::Programming,
            'photography', 'media', 'التصوير', 'تصوير', 'photo', '3d', '3d_visualization' => self::Photography,
            'design', 'تصميم', 'branding', 'print' => self::Design,
            'content', 'محتوى' => self::Content,
            'sales', 'المبيعات' => self::Sales,
            'revision' => self::Revision,
            default => self::tryFrom($normalized),
        };
    }
}
