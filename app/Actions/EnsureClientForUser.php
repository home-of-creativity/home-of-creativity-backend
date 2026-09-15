<?php

namespace App\Actions;

use App\Models\Client;
use App\Models\User;

class EnsureClientForUser
{
    public function __construct(private PushClientToOdoo $pushClientToOdoo) {}

    public function handle(User $user): Client
    {
        $client = $user->client()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'telegram_user_id' => $user->telegram_user_id,
                'locale' => $user->locale ?? 'ar',
            ],
        );

        return $this->pushClientToOdoo->handle($client);
    }
}
