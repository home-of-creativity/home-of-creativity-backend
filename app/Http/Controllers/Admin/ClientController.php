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
        $like = $search === '' ? '' : '%'.addcslashes($search, '%_\\').'%';

        $paginator = Client::query()
            ->visibleOnDashboard()
            ->withCount('requests')
            ->when($like !== '', function ($query) use ($like) {
                $query->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('company_name', 'like', $like)
                        ->orWhere('odoo_stage_name', 'like', $like);
                });
            })
            ->when($request->query('odoo') === 'linked', function ($query) {
                $query->where(function ($inner) {
                    $inner->where(fn ($lead) => $lead->whereNotNull('odoo_lead_id')->where('odoo_lead_id', '!=', ''))
                        ->orWhere(fn ($partner) => $partner->whereNotNull('odoo_partner_id')->where('odoo_partner_id', '!=', ''));
                });
            })
            ->when($request->query('odoo') === 'unlinked', function ($query) {
                $query->where(function ($inner) {
                    $inner->whereNull('odoo_lead_id')->orWhere('odoo_lead_id', '');
                })->where(function ($inner) {
                    $inner->whereNull('odoo_partner_id')->orWhere('odoo_partner_id', '');
                });
            })
            ->when($request->query('company') === 'yes', fn ($query) => $query->whereNotNull('company_name')->where('company_name', '!=', ''))
            ->when($request->query('company') === 'no', function ($query) {
                $query->where(fn ($inner) => $inner->whereNull('company_name')->orWhere('company_name', ''));
            })
            ->when($request->query('phone') === 'yes', fn ($query) => $query->whereNotNull('phone')->where('phone', '!=', ''))
            ->when($request->query('phone') === 'no', function ($query) {
                $query->where(fn ($inner) => $inner->whereNull('phone')->orWhere('phone', ''));
            })
            ->when($request->query('channel') === 'telegram', function ($query) {
                $query->whereNotNull('telegram_user_id')
                    ->where('telegram_user_id', '!=', '')
                    ->where('telegram_user_id', 'not like', Client::WHATSAPP_PREFIX.'%');
            })
            ->when($request->query('channel') === 'whatsapp', fn ($query) => $query->where('telegram_user_id', 'like', Client::WHATSAPP_PREFIX.'%'))
            ->when($request->query('channel') === 'none', function ($query) {
                $query->where(fn ($inner) => $inner->whereNull('telegram_user_id')->orWhere('telegram_user_id', ''));
            })
            ->when(filled($request->query('stage')), fn ($query) => $query->where('odoo_stage_name', (string) $request->query('stage')))
            ->when($request->query('drive') === 'yes', fn ($query) => $query->whereNotNull('google_drive_folder_id')->where('google_drive_folder_id', '!=', ''))
            ->when($request->query('drive') === 'no', function ($query) {
                $query->where(fn ($inner) => $inner->whereNull('google_drive_folder_id')->orWhere('google_drive_folder_id', ''));
            })
            ->latest('id')
            ->paginate($request->perPage());

        $stages = Client::query()
            ->visibleOnDashboard()
            ->whereNotNull('odoo_stage_name')
            ->where('odoo_stage_name', '!=', '')
            ->distinct()
            ->orderBy('odoo_stage_name')
            ->limit(100)
            ->pluck('odoo_stage_name')
            ->values();

        return ClientResource::collection($paginator)->additional([
            'message' => 'ok',
            'stages' => $stages,
        ]);
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
