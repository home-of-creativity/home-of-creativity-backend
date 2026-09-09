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
    public function index(PaginatedIndexRequest $request)
    {
        return ClientResource::collection(
            Client::query()->withCount('requests')->latest('id')->paginate($request->perPage())
        )->additional(['message' => 'ok']);
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
