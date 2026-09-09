<?php

namespace App\Actions;

use App\Models\Client;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;

class StoreClient
{
    public function __construct(private OdooClient $odoo) {}

    /**
     * @param  array{name: string, email?: string|null, phone?: string|null, telegram_user_id?: string|null, locale?: string|null}  $data
     */
    public function handle(array $data): Client
    {
        $client = Client::query()->create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'telegram_user_id' => $data['telegram_user_id'] ?? null,
            'locale' => $data['locale'] ?? 'ar',
        ]);

        if (! $this->odoo->configured()) {
            return $client;
        }

        try {
            $partnerId = $this->odoo->createOrReusePartner(
                $client->name,
                $client->email,
                $client->phone,
            );
            $client->forceFill(['odoo_partner_id' => $partnerId])->save();
        } catch (\Throwable $exception) {
            Log::warning('Odoo partner create failed for dashboard client.', [
                'client_id' => $client->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $client->fresh() ?? $client;
    }
}
