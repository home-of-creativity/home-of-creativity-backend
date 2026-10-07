<?php

namespace App\Support;

use App\Enums\RequestStatus;

class StatusLabel
{
    public static function requestAr(string $status): string
    {
        return RequestStatus::tryFrom($status)?->labelAr() ?? $status;
    }

    public static function request(string $status, string $locale = 'ar'): string
    {
        $enum = RequestStatus::tryFrom($status);
        if ($enum === null) {
            return $status;
        }

        return $locale === 'en' ? $enum->labelEn() : $enum->labelAr();
    }
}
