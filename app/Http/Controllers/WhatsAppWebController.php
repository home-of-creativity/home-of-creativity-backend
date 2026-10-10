<?php

namespace App\Http\Controllers;

use App\Actions\HandleWhatsAppInbound;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppWebController extends Controller
{
    public function incoming(Request $request, HandleWhatsAppInbound $handleWhatsAppInbound): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'profile_name' => ['nullable', 'string', 'max:120'],
            'message_id' => ['required', 'string', 'max:128'],
            'text' => ['nullable', 'string', 'max:4000'],
            'button_id' => ['nullable', 'string', 'max:256'],
            'from_me' => ['sometimes', 'boolean'],
            'media' => ['nullable', 'array'],
            'media.kind' => ['required_with:media', 'in:image,document,audio'],
            'media.mime' => ['nullable', 'string', 'max:80'],
            'media.filename' => ['nullable', 'string', 'max:180'],
            'media.data_base64' => ['required_with:media', 'string', 'max:12000000'],
        ]);

        $phone = Client::normalizeWhatsAppPhone((string) $data['phone']);
        if ($phone === '') {
            return response()->json(['status' => 'ignored']);
        }

        if ($request->boolean('from_me')) {
            $handleWhatsAppInbound->holdForHuman($phone);

            return response()->json(['status' => 'held']);
        }

        $message = [
            'id' => (string) $data['message_id'],
            'from' => $phone,
        ];
        $buttonId = trim((string) ($data['button_id'] ?? ''));
        $media = is_array($data['media'] ?? null) ? $data['media'] : null;

        if ($buttonId !== '') {
            $message['type'] = 'interactive';
            $message['interactive'] = [
                'type' => 'button_reply',
                'button_reply' => ['id' => $buttonId],
            ];
        } elseif ($media !== null) {
            $kind = (string) $media['kind'];
            $message['type'] = $kind;
            $message[$kind] = [
                'id' => (string) $data['message_id'],
                'mime_type' => (string) ($media['mime'] ?? 'application/octet-stream'),
                'filename' => (string) ($media['filename'] ?? 'file'),
                'file_base64' => (string) $media['data_base64'],
            ];
        } else {
            $message['type'] = 'text';
            $message['text'] = ['body' => (string) ($data['text'] ?? '')];
        }

        $handleWhatsAppInbound->handle([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'contacts' => [[
                            'profile' => ['name' => (string) ($data['profile_name'] ?? '')],
                        ]],
                        'messages' => [$message],
                    ],
                ]],
            ]],
        ]);

        return response()->json(['status' => 'ok']);
    }
}
