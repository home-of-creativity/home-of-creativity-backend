<?php

namespace App\Support;

use App\Enums\RequestStatus;

class StatusLabel
{
    public static function requestAr(string $status): string
    {
        return RequestStatus::tryFrom($status)?->labelAr() ?? $status;
    }
}
