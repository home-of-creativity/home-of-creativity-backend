<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DevBotController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json([
            'data' => ['ok' => true],
            'message' => 'ok',
        ]);
    }

    public function status(): JsonResponse
    {
        $url = (string) config('services.dev.health_url');
        $up = false;
        try {
            $up = Http::timeout(8)->get($url)->successful();
        } catch (\Throwable) {
            $up = false;
        }

        return response()->json([
            'data' => [
                'up' => $up,
                'health_url' => $url,
                'reported_down' => (bool) Cache::get('dev.health.down'),
            ],
            'message' => $up ? 'up' : 'down',
        ]);
    }
}
