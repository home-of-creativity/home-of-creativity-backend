<?php

namespace App\Http\Controllers;

use App\Models\BotDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Half-written client bot conversations (manual request, reject or revision
 * reason, receipt target, profile step) so a bot restart resumes the same step.
 */
class BotDraftController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = BotDraft::query()
            ->where('bot', 'client')
            ->where('updated_at', '>=', now()->subDays(BotDraft::KEEP_DAYS))
            ->orderByDesc('updated_at')
            ->limit(1000)
            ->get(['telegram_user_id', 'payload']);

        return response()->json([
            'data' => $rows->map(fn (BotDraft $draft): array => [
                'telegram_user_id' => $draft->telegram_user_id,
                'payload' => $draft->payload,
            ])->values(),
            'message' => 'ok',
        ]);
    }

    public function update(Request $request, string $telegramUserId): JsonResponse
    {
        abort_unless(preg_match('/^\d{1,20}$/', $telegramUserId) === 1, 422, 'Invalid Telegram id.');

        $validated = $request->validate([
            'payload' => ['present', 'array'],
            'payload.user_data' => ['sometimes', 'array'],
            'payload.conversations' => ['sometimes', 'array'],
        ]);

        $payload = $validated['payload'];
        if (strlen((string) json_encode($payload, JSON_UNESCAPED_UNICODE)) > BotDraft::MAX_BYTES) {
            throw ValidationException::withMessages(['payload' => 'Draft is too large.']);
        }

        if (($payload['user_data'] ?? []) === [] && ($payload['conversations'] ?? []) === []) {
            BotDraft::query()->where('bot', 'client')->where('telegram_user_id', $telegramUserId)->delete();

            return response()->json(['data' => ['stored' => false], 'message' => 'Draft cleared.']);
        }

        BotDraft::query()->updateOrCreate(
            ['bot' => 'client', 'telegram_user_id' => $telegramUserId],
            ['payload' => $payload],
        );

        return response()->json(['data' => ['stored' => true], 'message' => 'Draft saved.']);
    }
}
