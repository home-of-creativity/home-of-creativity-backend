<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateClientChannelsRequest;
use App\Services\WhatsAppWebClient;
use App\Support\ClientChannelGate;
use Illuminate\Http\JsonResponse;

class ClientChannelController extends Controller
{
    public function update(UpdateClientChannelsRequest $request): JsonResponse
    {
        ClientChannelGate::setTelegramEnabled($request->boolean('telegram_enabled'));
        ClientChannelGate::setWhatsAppEnabled($request->boolean('whatsapp_enabled'));

        return response()->json([
            'data' => ClientChannelGate::payload(),
            'message' => 'ok',
        ]);
    }

    public function whatsappWeb(WhatsAppWebClient $whatsAppWeb): JsonResponse
    {
        return response()->json([
            'data' => array_merge(ClientChannelGate::payload(), $whatsAppWeb->status()),
        ]);
    }
}
