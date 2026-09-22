<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateClientChannelsRequest;
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
}
