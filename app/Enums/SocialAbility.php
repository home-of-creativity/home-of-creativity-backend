<?php

namespace App\Enums;

enum SocialAbility: string
{
    case Accounts = 'accounts';
    case Create = 'create';
    case Approve = 'approve';
    case Engage = 'engage';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
