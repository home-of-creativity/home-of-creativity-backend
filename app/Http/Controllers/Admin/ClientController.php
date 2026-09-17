<?php

namespace App\Http\Controllers\Admin;

use App\Actions\DeleteClient;
use App\Actions\ImportOdooCrmClients;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClientController extends Controller
{
    public function index(
        PaginatedIndexRequest $request,
        OdooClient $odoo,
        ImportOdooCrmClients $importOdooCrmClients,
    ) {
        if ($odoo->configured() && (app()->runningUnitTests() || PHP_SAPI !== 'cli-server')) {
            try {
                Cache::remember('odoo:crm:index-pull', 60, function () use ($importOdooCrmClients): bool {
                    $importOdooCrmClients->handle(200);

                    return true;
                });
            } catch (Throwable $exception) {
                Log::warning('Odoo CRM pull on clients index failed.', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

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
