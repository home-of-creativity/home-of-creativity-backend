<?php

namespace App\Http\Middleware;

use App\Support\ClientChannelGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientTelegramEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ClientChannelGate::telegramEnabled()) {
            return $next($request);
        }

        return response()->json([
            'data' => null,
            'message' => ClientChannelGate::TELEGRAM_PAUSED_MESSAGE,
            'code' => 'channel_paused',
        ], 503);
    }
}
