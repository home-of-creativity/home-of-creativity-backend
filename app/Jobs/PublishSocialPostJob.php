<?php

namespace App\Jobs;

use App\Enums\SocialActivityAction;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPublishStatus;
use App\Models\SocialPost;
use App\Models\SocialPostAccount;
use App\Services\SocialActivityLogger;
use App\Services\SocialPublisher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishSocialPostJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public int $socialPostId) {}

    public function uniqueId(): string
    {
        return (string) $this->socialPostId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(SocialPublisher $publisher, SocialActivityLogger $logger): void
    {
        $post = SocialPost::query()->with(['accounts', 'media'])->find($this->socialPostId);
        if (! $post) {
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

            $result = $publisher->publish($account, $post);

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
}
