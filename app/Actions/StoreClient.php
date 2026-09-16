<?php

namespace App\Actions;

use App\Models\Client;

class StoreClient
{
    public function __construct(private PushClientToOdoo $pushClientToOdoo) {}

    /**
     * @param  array{name: string, email?: string|null, phone?: string|null, telegram_user_id?: string|null, locale?: string|null}  $data
     */
    public function handle(array $data): Client
    {
        $client = Client::query()->create([
            'name' => $data['name'],
            'company_name' => $data['company_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'telegram_user_id' => $data['telegram_user_id'] ?? null,
            'locale' => $data['locale'] ?? 'ar',
        ]);

        $client = $this->pushClientToOdoo->handle($client);

        return app(PushClientLeadToOdoo::class)->handle($client);
    }
}
