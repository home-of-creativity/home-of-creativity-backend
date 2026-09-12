<?php

namespace App\Enums;

enum SocialPlatform: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Linkedin = 'linkedin';
    case X = 'x';
    case Tiktok = 'tiktok';
    case Youtube = 'youtube';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
