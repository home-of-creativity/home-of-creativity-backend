<?php

namespace App\Enums;

enum SocialPlacement: string
{
    case Feed = 'feed';
    case Reel = 'reel';
    case Story = 'story';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
