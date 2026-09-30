<?php

namespace App\Http\Controllers;

use App\Services\DevAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SentryWebhookController extends Controller
{
    public function __invoke(Request $request, DevAlert $alert): JsonResponse
    {
        $action = (string) $request->input('action', '');
        if (in_array($action, ['resolved', 'ignored', 'archived'], true)) {
            return $this->ok();
        }

        $issue = $this->issue($request);
        $title = trim((string) ($issue['title'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($request->input('data.description_text')
                ?? $request->input('data.description')
                ?? $request->input('message')
                ?? ''));
        }
        if ($title === '') {
            return $this->ok();
        }

        $url = trim((string) ($issue['permalink'] ?? $issue['web_url'] ?? $issue['url'] ?? $request->input('data.web_url') ?? ''));
        $id = trim((string) ($issue['id'] ?? ''));
        $key = $id !== '' ? 'sentry-'.$id : 'sentry-'.md5($title);
        $this->remember($id !== '' ? $id : $key, $title, $url);
        $alert->once($key, 'Sentry: '.$title.($url !== '' ? "\n".$url : ''), 360);

        return $this->ok();
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(Request $request): array
    {
        $issue = $request->input('data.issue');
        if (is_array($issue)) {
            return $issue;
        }

        $event = $request->input('data.event');
        if (! is_array($event)) {
            return [];
        }

        return [
            'id' => $event['issue_id'] ?? $event['event_id'] ?? '',
            'title' => $event['title'] ?? $event['message'] ?? '',
            'permalink' => $event['web_url'] ?? $event['url'] ?? '',
        ];
    }

    private function remember(string $id, string $title, string $url): void
    {
        $items = Cache::get('dev.sentry.recent', []);
        if (! is_array($items)) {
            $items = [];
        }

        $items = array_values(array_filter(
            $items,
            fn ($item): bool => is_array($item) && (string) ($item['id'] ?? '') !== $id,
        ));
        array_unshift($items, [
            'id' => $id,
            'title' => $title,
            'url' => $url,
            'at' => now()->toIso8601String(),
        ]);

        Cache::put('dev.sentry.recent', array_slice($items, 0, 8), now()->addDays(7));
    }

    private function ok(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'message' => 'ok',
        ]);
    }
}
