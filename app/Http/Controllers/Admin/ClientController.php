<?php

namespace App\Http\Controllers\Admin;

use App\Actions\DeleteClient;
use App\Actions\PushClientLeadToOdoo;
use App\Actions\PushClientToOdoo;
use App\Actions\StoreClient;
use App\Actions\UpdateClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginatedIndexRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Services\OdooClient;
use Illuminate\Http\JsonResponse;

class ClientController extends Controller
{
    public function index(
        PaginatedIndexRequest $request,
        PushClientToOdoo $pushClientToOdoo,
        PushClientLeadToOdoo $pushClientLeadToOdoo,
    ) {
        $search = trim((string) $request->query('search', ''));

        $paginator = Client::query()
            ->visibleOnDashboard()
            ->withCount('requests')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate($request->perPage());

        $pushed = 0;
        $paginator->setCollection(
            $paginator->getCollection()->map(function (Client $client) use (&$pushed, $pushClientToOdoo, $pushClientLeadToOdoo): Client {
                if (
                    $pushed >= 3
                    || ! filled($client->telegram_user_id)
                    || ! $client->profileComplete()
                    || filled($client->odoo_lead_id)
                ) {
                    return $client;
                }

                $pushed++;

                return $pushClientLeadToOdoo->handle($pushClientToOdoo->handle($client), false, false);
            })
        );

        return ClientResource::collection($paginator)->additional(['message' => 'ok']);
    }

    public function store(StoreClientRequest $request, StoreClient $storeClient, OdooClient $odoo): JsonResponse
    {
        $client = $storeClient->handle($request->validated());

        return ClientResource::make($client->loadCount('requests'))
            ->additional([
                'message' => $odoo->configured() && (filled($client->odoo_partner_id) || filled($client->odoo_lead_id))
                    ? 'Client created and linked to Odoo.'
                    : 'Client created.',
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateClientRequest $request, Client $client, UpdateClient $updateClient): ClientResource
    {
        $updated = $updateClient->handle($client, $request->validated());

        return ClientResource::make($updated->loadCount('requests'))
            ->additional(['message' => 'Client updated in dashboard and Odoo.']);
    }

    public function destroy(Client $client, DeleteClient $deleteClient): JsonResponse
    {
        $deleteClient->handle($client);

        return response()->json([
            'data' => null,
            'message' => 'Client deleted from dashboard and Odoo.',
        ]);
    }
}
