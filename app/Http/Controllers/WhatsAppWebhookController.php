<?php

namespace App\Http\Controllers;

use App\Actions\HandleWhatsAppInbound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $mode = (string) ($request->query->get('hub.mode') ?: $request->query->get('hub_mode') ?: '');
        $token = (string) ($request->query->get('hub.verify_token') ?: $request->query->get('hub_verify_token') ?: '');
        $challenge = (string) ($request->query->get('hub.challenge') ?: $request->query->get('hub_challenge') ?: '');
        $expected = (string) config('services.whatsapp.verify_token');

        if ($mode !== 'subscribe' || $expected === '' || ! hash_equals($expected, $token)) {
            abort(403, 'WhatsApp webhook verification failed.');
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function incoming(Request $request, HandleWhatsAppInbound $inbound): JsonResponse
    {
        try {
            $inbound->handle($request->all());
        } catch (Throwable $exception) {
            Log::error('WhatsApp inbound failed.', [
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }
}
