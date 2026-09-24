<?php

namespace App\Http\Controllers;

use App\Services\DevAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SentryWebhookController extends Controller
{
    public function __invoke(Request $request, DevAlert $alert): JsonResponse
    {
        $issue = $request->input('data.issue');
        $issue = is_array($issue) ? $issue : [];
        $title = (string) ($issue['title'] ?? $request->input('message') ?? 'Sentry alert');
        $url = (string) ($issue['web_url'] ?? $issue['url'] ?? '');
        $id = (string) ($issue['id'] ?? '');
        $key = $id !== '' ? 'sentry-'.$id : 'sentry-'.md5($title);
        $alert->once($key, 'Sentry: '.$title.($url !== '' ? "\n".$url : ''), 360);

        return response()->json([
            'data' => null,
            'message' => 'ok',
        ]);
    }
}
