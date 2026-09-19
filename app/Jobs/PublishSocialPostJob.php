<?php

namespace App\Jobs;

use App\Enums\SocialActivityAction;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPublishStatus;
use App\Models\SocialPost;
use App\Models\SocialPostAccount;
use App\Services\SocialActivityLogger;
use App\Services\SocialPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PublishSocialPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 360;

    public function __construct(public int $socialPostId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public static function dispatchFor(int $socialPostId, bool $immediately = false): void
    {
        $key = self::dispatchLockKey($socialPostId);
        if ($immediately) {
            Cache::forget($key);
            self::dispatchSync($socialPostId);

            return;
        }

        if (! Cache::add($key, 1, 180)) {
            return;
        }

        self::dispatch($socialPostId);
    }

    public function handle(SocialPublisher $publisher, SocialActivityLogger $logger): void
    {
        $lock = Cache::lock(self::runLockKey($this->socialPostId), $this->timeout);
        if (! $lock->get()) {
            return;
        }

        try {
            $this->publishPost($publisher, $logger);
        } finally {
            optional($lock)->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed($exception?->getMessage() ?: 'Publishing failed.');
    }

    private function publishPost(SocialPublisher $publisher, SocialActivityLogger $logger): void
    {
        $post = SocialPost::query()->with(['accounts', 'media'])->find($this->socialPostId);
        if (! $post || $post->status === SocialPostStatus::Published) {
            return;
        }

        $post->forceFill([
            'status' => SocialPostStatus::Publishing,
            'last_error' => null,
        ])->save();

        $failures = [];

        foreach ($post->targets()->with('account')->get() as $target) {
            /** @var SocialPostAccount $target */
            $account = $target->account;
            if (! $account) {
                $target->forceFill([
                    'status' => SocialPublishStatus::Failed,
                    'last_error' => 'Account is missing.',
                ])->save();
                $failures[] = 'Account is missing.';

                continue;
            }

            if ($target->status === SocialPublishStatus::Published && filled($target->external_id)) {
                continue;
            }

            $target->forceFill([
                'status' => SocialPublishStatus::Publishing,
                'last_error' => null,
            ])->save();

            try {
                $result = $publisher->publish($account, $post);
            } catch (Throwable $exception) {
                $result = [
                    'ok' => false,
                    'external_id' => $target->external_id,
                    'error' => $exception->getMessage() ?: 'Publishing failed.',
                ];
            }

            $target->forceFill([
                'status' => $result['ok'] ? SocialPublishStatus::Published : SocialPublishStatus::Failed,
                'external_id' => $result['external_id'],
                'published_at' => $result['ok'] ? now() : null,
                'last_error' => $result['error'],
            ])->save();

            if (! $result['ok'] && is_string($result['error'])) {
                $failures[] = $account->name.': '.$result['error'];
            }
        }

        if ($failures === []) {
            $post->forceFill([
                'status' => SocialPostStatus::Published,
                'published_at' => now(),
                'last_error' => null,
            ])->save();
            $logger->log(null, SocialActivityAction::Published, $post, [
                'published_at' => now()->toIso8601String(),
            ]);

            return;
        }

        $post->forceFill([
            'status' => SocialPostStatus::Failed,
            'last_error' => implode(' | ', $failures),
        ])->save();
        $logger->log(null, SocialActivityAction::Failed, $post, [
            'error' => $post->last_error,
        ]);
    }

    private function markFailed(string $message): void
    {
        $post = SocialPost::query()->find($this->socialPostId);
        if (! $post || $post->status !== SocialPostStatus::Publishing) {
            return;
        }

        $post->targets()
            ->where('status', '!=', SocialPublishStatus::Published->value)
            ->update([
                'status' => SocialPublishStatus::Failed->value,
                'last_error' => $message,
            ]);

        $post->forceFill([
            'status' => SocialPostStatus::Failed,
            'last_error' => $message,
        ])->save();

        app(SocialActivityLogger::class)->log(null, SocialActivityAction::Failed, $post, [
            'error' => $message,
        ]);
    }

    private static function dispatchLockKey(int $socialPostId): string
    {
        return 'social-publish-dispatch:'.$socialPostId;
    }

    private static function runLockKey(int $socialPostId): string
    {
        return 'social-publish-run:'.$socialPostId;
    }
}
