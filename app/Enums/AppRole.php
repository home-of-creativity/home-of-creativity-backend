<?php

namespace App\Enums;

/**
 * Which side of the mobile app a signed-in account opens.
 */
enum AppRole: string
{
    case Admin = 'admin';
    case Employee = 'employee';
    case Client = 'client';

    public function ability(): string
    {
        return 'role:'.$this->value;
    }
}
