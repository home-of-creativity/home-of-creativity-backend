<?php

namespace App\Http\Controllers\Admin;

use App\Actions\StoreClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginatedIndexRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Services\OdooClient;
use Illuminate\Http\JsonResponse;

class ClientController extends Controller
{
    public function index(PaginatedIndexRequest $request, OdooClient $odoo)
    {
        $paginator = Client::query()->withCount('requests')->latest('id')->paginate($request->perPage());

        if ($odoo->configured()) {
            $paginator->getCollection()->transform(function (Client $client) use ($odoo): Client {
                if (! filled($client->odoo_lead_id)) {
                    return $client;
                }

                $snapshot = $odoo->leadSnapshot((int) $client->odoo_lead_id);
                if ($snapshot === null) {
                    return $client;
                }

                if (($snapshot['stage'] ?? null) !== $client->odoo_stage_name) {
                    $client->forceFill(['odoo_stage_name' => $snapshot['stage']])->save();
                }

                $client->setAttribute('odoo_live', $snapshot);

                return $client;
            });
        }

        return ClientResource::collection($paginator)->additional(['message' => 'ok']);
    }

    public function store(StoreClientRequest $request, StoreClient $storeClient, OdooClient $odoo): JsonResponse
    {
        $client = $storeClient->handle($request->validated());

        return ClientResource::make($client->loadCount('requests'))
            ->additional([
                'message' => $odoo->configured() && filled($client->odoo_partner_id)
                    ? 'Client created and linked to Odoo.'
                    : 'Client created.',
            ])
            ->response()
            ->setStatusCode(201);
    }
}
