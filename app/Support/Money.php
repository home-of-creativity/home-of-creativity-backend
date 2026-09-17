<?php

namespace App\Support;

class Money
{
    public static function currency(): string
    {
        return 'USD';
    }

    public static function format(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 2).' '.self::currency();
    }
}
