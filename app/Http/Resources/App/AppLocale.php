<?php

namespace App\Http\Resources\App;

use Illuminate\Http\Request;

/**
 * The app sends its in-app language as Accept-Language. Arabic otherwise.
 */
final class AppLocale
{
    public static function from(Request $request): string
    {
        return str_starts_with(strtolower((string) $request->header('Accept-Language')), 'en') ? 'en' : 'ar';
    }
}
