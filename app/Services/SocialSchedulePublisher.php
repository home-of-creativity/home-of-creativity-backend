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

        SocialPost::query()->dueForPublish()->orderBy('scheduled_at')->each(function (SocialPost $post) use (&$count): void {
            $post->forceFill([
                'status' => SocialPostStatus::Publishing,
                'last_error' => null,
            ])->save();

            PublishSocialPostJob::dispatch($post->id);
            $count++;
        });

        return $count;
    }
}
