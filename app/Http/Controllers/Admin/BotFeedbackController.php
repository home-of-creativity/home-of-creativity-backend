<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BotFeedbackSurvey;
use App\Http\Controllers\Controller;
use App\Models\BotFeedback;
use Illuminate\Http\JsonResponse;

class BotFeedbackController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = BotFeedback::query()
            ->with('client:id,name,company_name,phone')
            ->whereNotNull('score')
            ->latest('answered_at')
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (BotFeedback $row): array => [
                'id' => $row->id,
                'score' => $row->score,
                'reason' => $row->reason,
                'reason_label' => $row->reason !== null && isset(BotFeedbackSurvey::REASONS[$row->reason])
                    ? BotFeedbackSurvey::REASONS[$row->reason][0]
                    : null,
                'source' => $row->source,
                'channel' => $row->channel,
                'asked_at' => optional($row->asked_at)?->toIso8601String(),
                'answered_at' => optional($row->answered_at)?->toIso8601String(),
                'client_id' => $row->client_id,
                'client_name' => $row->client?->name,
                'company_name' => $row->client?->company_name,
                'phone' => $row->client?->phone,
            ])->values(),
        ]);
    }
}
