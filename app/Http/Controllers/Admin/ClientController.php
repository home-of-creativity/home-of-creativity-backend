<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AssignClientDriveFolder;
use App\Actions\DeleteClient;
use App\Actions\PushClientLeadToOdoo;
use App\Actions\PushClientToOdoo;
use App\Actions\StoreClient;
use App\Actions\UpdateClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignClientDriveFolderRequest;
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
        PushClientToOdoo $pushClient,
        PushClientLeadToOdoo $pushLead,
    ) {
        $this->pushPendingTelegramLeads($pushClient, $pushLead);

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

    /**
     * Complete Telegram clients stay hidden until name, phone, and company
     * exist. Once they are visible, opening the list creates the missing
     * تلغرام opportunity. Capped so a full table is not pushed on every page view.
     */
    private function pushPendingTelegramLeads(PushClientToOdoo $pushClient, PushClientLeadToOdoo $pushLead): void
    {
        Client::query()
            ->visibleOnDashboard()
            ->whereNotNull('telegram_user_id')
            ->where('telegram_user_id', '!=', '')
            ->where(function ($query): void {
                $query->whereNull('odoo_lead_id')->orWhere('odoo_lead_id', '');
            })
            ->orderBy('id')
            ->limit(3)
            ->get()
            ->each(function (Client $client) use ($pushClient, $pushLead): void {
                $client = $pushClient->handle($client);
                $pushLead->handle($client, false, false);
            });
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

    public function driveFolder(
        AssignClientDriveFolderRequest $request,
        Client $client,
        AssignClientDriveFolder $assign,
    ): ClientResource {
        $updated = $assign->handle(
            $client,
            $request->validated('mode'),
            $request->validated('folder'),
            $request->validated('name'),
            $request->validated('parent'),
        );

        return ClientResource::make($updated)
            ->additional(['message' => 'Drive folder saved.']);
    }
}
