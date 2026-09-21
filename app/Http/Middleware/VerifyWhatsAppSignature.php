<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWhatsAppSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.whatsapp.app_secret');
        $header = (string) $request->header('X-Hub-Signature-256', '');

        if ($secret === '' || ! str_starts_with($header, 'sha256=')) {
            abort(401, 'Invalid WhatsApp signature.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if (! hash_equals($expected, $header)) {
            abort(401, 'Invalid WhatsApp signature.');
        }

        return $next($request);
    }
}
