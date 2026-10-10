<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/** One client chat cannot hammer the bot API. */
class ThrottleBotSender
{
    public function handle(Request $request, Closure $next): Response
    {
        $sender = (string) ($request->input('telegram_user_id') ?: $request->query('telegram_user_id') ?: $request->ip());
        $key = 'hoc:bot-sender:'.sha1($sender);
        if (RateLimiter::tooManyAttempts($key, 60)) {
            abort(429, 'استنى شوي وابعت من جديد.');
        }
        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
