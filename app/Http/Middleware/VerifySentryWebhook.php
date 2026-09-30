<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySentryWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.sentry.webhook_secret');
        $provided = (string) $request->header('X-Webhook-Secret', $request->header('X-N8N-Secret', ''));

        if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) {
            return $next($request);
        }

        $signature = (string) $request->header('Sentry-Hook-Signature', '');
        $digest = hash_hmac('sha256', $request->getContent(), $expected);
        if ($expected !== '' && $signature !== '' && hash_equals($digest, $signature)) {
            return $next($request);
        }

        abort(401, 'Invalid webhook secret.');
    }
}
