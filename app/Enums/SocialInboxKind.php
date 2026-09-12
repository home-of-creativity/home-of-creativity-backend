<?php

namespace App\Enums;

enum SocialInboxKind: string
{
    case Comment = 'comment';
    case Message = 'message';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
