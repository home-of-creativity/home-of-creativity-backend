<?php

namespace App\Http\Controllers;

use App\Services\DevDigest;
use App\Services\DevHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class DevBotController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json([
            'data' => ['ok' => true],
            'message' => 'ok',
        ]);
    }

    public function status(DevHealth $health): JsonResponse
    {
        $url = $health->labelUrl();
        $up = $health->up();

        return response()->json([
            'data' => [
                'up' => $up,
                'health_url' => $url,
                'reported_down' => (bool) Cache::get('dev.health.down'),
            ],
            'message' => $up ? 'up' : 'down',
        ]);
    }

    public function bots(DevDigest $digest): JsonResponse
    {
        return $this->text($digest->botsText());
    }

    public function queue(DevDigest $digest): JsonResponse
    {
        return $this->text($digest->queueText());
    }

    public function digest(DevDigest $digest): JsonResponse
    {
        return $this->text($digest->text());
    }

    private function text(string $text): JsonResponse
    {
        return response()->json([
            'data' => ['text' => $text],
            'message' => 'ok',
        ]);
    }
}
