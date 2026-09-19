<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmployeeStatus;
use App\Enums\SocialPostStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Models\SocialPost;
use Illuminate\Http\JsonResponse;

class LiveController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $requestCount = ServiceRequest::query()->count();
        $socialCount = SocialPost::query()->count();
        $requestStamp = ServiceRequest::query()->max('updated_at');
        $socialStamp = SocialPost::query()->max('updated_at');

        return response()->json([
            'data' => [
                'requests_stamp' => $this->stamp($requestStamp, $requestCount),
                'social_stamp' => $this->stamp($socialStamp, $socialCount),
                'requests_count' => $requestCount,
                'pending_employees' => Employee::query()->where('status', EmployeeStatus::Pending)->count(),
                'publishing' => SocialPost::query()->where('status', SocialPostStatus::Publishing)->count(),
                'requests' => ServiceRequest::query()
                    ->latest('updated_at')
                    ->limit(8)
                    ->get(['id', 'number', 'status', 'updated_at'])
                    ->map(fn (ServiceRequest $request) => [
                        'id' => $request->id,
                        'number' => $request->number,
                        'status' => $request->status instanceof \BackedEnum ? $request->status->value : $request->status,
                        'updated_at' => $request->updated_at?->toIso8601String(),
                    ])
                    ->all(),
                'posts' => SocialPost::query()
                    ->latest('updated_at')
                    ->limit(8)
                    ->get(['id', 'status', 'body', 'updated_at'])
                    ->map(fn (SocialPost $post) => [
                        'id' => $post->id,
                        'status' => $post->status instanceof \BackedEnum ? $post->status->value : $post->status,
                        'body' => mb_substr((string) $post->body, 0, 72),
                        'updated_at' => $post->updated_at?->toIso8601String(),
                    ])
                    ->all(),
            ],
            'message' => 'ok',
        ]);
    }

    private function stamp(mixed $updatedAt, int $count): string
    {
        $time = $updatedAt ? (string) $updatedAt : '0';

        return $time.'|'.$count;
    }
}
