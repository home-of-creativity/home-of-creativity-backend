<?php

namespace App\Services;

use App\Enums\SocialPostStatus;
use App\Jobs\PublishSocialPostJob;
use App\Models\SocialPost;

class SocialSchedulePublisher
{
    public function dispatchDue(): int
    {
        $count = 0;

        SocialPost::query()
            ->where(function ($query): void {
                $query->dueForPublish()
                    ->orWhere(fn ($stuck) => $stuck->stuckPublishing());
            })
            ->orderBy('scheduled_at')
            ->each(function (SocialPost $post) use (&$count): void {
                if ($post->status !== SocialPostStatus::Publishing) {
                    $post->forceFill([
                        'status' => SocialPostStatus::Publishing,
                        'last_error' => null,
                    ])->save();
                }

                PublishSocialPostJob::dispatchFor($post->id);
                $count++;
            });

        return $count;
    }
}
