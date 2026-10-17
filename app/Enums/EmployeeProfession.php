<?php

namespace App\Enums;

enum EmployeeProfession: string
{
    case Sales = 'sales';
    case Design = 'design';
    case Content = 'content';
    case Branding = 'branding';
    case Visualization3d = '3d_visualization';
    case Photography = 'photography';
    case Media = 'media';
    case Web = 'web';
    case Print = 'print';
    case CreativeDirection = 'creative_direction';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Professions that receive photography bookings and shoot alerts (includes legacy media). */
    public function isPhotographer(): bool
    {
        return in_array($this, self::photographers(), true);
    }

    /**
     * @return list<self>
     */
    public static function photographers(): array
    {
        return [self::Photography, self::Media];
    }

    /**
     * @return list<string>
     */
    public static function photographerValues(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::photographers());
    }
}
