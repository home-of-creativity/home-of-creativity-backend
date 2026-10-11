<?php

namespace App\Services\AppAuth;

use App\Models\User;

final readonly class AppOtpTarget
{
    /**
     * @param  'client'|'staff'  $bot
     * @param  'ar'|'en'  $locale
     */
    public function __construct(
        public User $user,
        public string $chatId,
        public string $bot,
        public string $locale,
    ) {}
}
