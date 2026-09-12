<?php

namespace App\Services;

use App\Enums\SocialPostStatus;
use App\Models\SocialInboxItem;
use App\Models\SocialPost;
use App\Models\SocialPostMedia;
use Illuminate\Support\Facades\Storage;

class SocialPublishedPostSync
{
    public function __construct(private SocialPublisher $publisher) {}

    public function prune(): int
    {
        $deleted = 0;

        SocialPost::query()
            ->where('status', SocialPostStatus::Published)
            ->with(['targets.account', 'media'])
            ->orderBy('id')
            ->each(function (SocialPost $post) use (&$deleted): void {
                $removedRemote = false;
                $stillLive = false;
                $unknown = false;

                foreach ($post->targets as $target) {
                    if (! filled($target->external_id) || ! $target->account) {
                        continue;
                    }

                    $live = $this->publisher->isLive($target->account, (string) $target->external_id);
                    if ($live === true) {
                        $stillLive = true;

                        continue;
                    }

                    if ($live === null) {
                        $unknown = true;

                        continue;
                    }

                    SocialInboxItem::deleteCommentsForSource($target->account->id, (string) $target->external_id);
                    $target->delete();
                    $removedRemote = true;
                }

                if ($stillLive || $unknown || ! $removedRemote) {
                    return;
                }

                $post->media->each(function (SocialPostMedia $media): void {
                    Storage::disk('public')->delete($media->path);
                });
                $post->delete();
                $deleted++;
            });

        return $deleted;
    }
}
