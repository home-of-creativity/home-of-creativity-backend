<?php

namespace App\Services;

use App\Enums\SocialActivityAction;
use App\Models\SocialActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SocialActivityLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(?User $user, SocialActivityAction $action, Model $subject, array $metadata = []): SocialActivity
    {
        return SocialActivity::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
