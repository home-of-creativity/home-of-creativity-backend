<?php

namespace App\Actions;

use App\Models\Client;

class UpdateClient
{
    public function __construct(
        private PushClientToOdoo $pushClientToOdoo,
        private PushClientLeadToOdoo $pushClientLeadToOdoo,
    ) {}

    /**
     * @param  array{name?: string, email?: string|null, phone?: string|null, telegram_user_id?: string|null, locale?: string|null, company_name?: string|null}  $data
     */
    public function handle(Client $client, array $data): Client
    {
        $client->fill($data)->save();

        $client = $this->pushClientToOdoo->handle($client, true);

        return $this->pushClientLeadToOdoo->handle($client, true);
    }
}
