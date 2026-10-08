<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ImportOdooCrmClients;
use App\Actions\ImportOdooCrmClientsFromExcel;
use App\Actions\SyncOdooEmployees;
use App\Actions\SyncOdooPartners;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportOdooCrmClientsExcelRequest;
use App\Http\Requests\StoreOdooInvoiceRequest;
use App\Http\Requests\StoreOdooQuotationRequest;
use App\Models\Client;
use App\Services\OdooClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OdooController extends Controller
{
    public function status(OdooClient $odoo): JsonResponse
    {
        return response()->json([
            'data' => [
                'configured' => $odoo->configured(),
                'url' => $odoo->configured() ? config('services.odoo.url') : null,
            ],
            'message' => 'ok',
        ]);
    }

    public function syncPartners(SyncOdooPartners $syncOdooPartners, OdooClient $odoo): JsonResponse
    {
        if (! $odoo->configured()) {
            return response()->json([
                'data' => ['synced' => 0, 'created' => 0, 'updated' => 0, 'pushed' => 0],
                'message' => 'Odoo is not configured.',
            ], 422);
        }

        try {
            $result = $syncOdooPartners->handle();
        } catch (\Throwable $exception) {
            Log::warning('Odoo partner sync failed.', ['error' => $exception->getMessage()]);

            return response()->json([
                'message' => 'Odoo sync failed: '.$exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'data' => $result,
            'message' => 'Odoo partners synced.',
        ]);
    }

    public function importCrmClients(ImportOdooCrmClients $importOdooCrmClients, OdooClient $odoo): JsonResponse
    {
        if (! $odoo->configured()) {
            return response()->json([
                'data' => [
                    'imported' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'pushed' => 0,
                    'crm_leads' => 0,
                    'partners' => 0,
                ],
                'message' => 'Odoo is not configured.',
            ], 422);
        }

        try {
            $result = $importOdooCrmClients->handle();
        } catch (\Throwable $exception) {
            Log::warning('Odoo CRM client import failed.', ['error' => $exception->getMessage()]);

            return response()->json([
                'message' => 'Odoo CRM import failed: '.$exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'data' => $result,
            'message' => 'Odoo CRM clients imported.',
        ]);
    }

    public function importCrmClientsExcel(
        ImportOdooCrmClientsExcelRequest $request,
        ImportOdooCrmClientsFromExcel $importOdooCrmClientsFromExcel,
        OdooClient $odoo,
    ): JsonResponse {
        if (! $odoo->configured()) {
            return response()->json([
                'message' => 'Odoo is not configured.',
            ], 422);
        }

        try {
            $result = $importOdooCrmClientsFromExcel->handle(
                $request->file('file')->getRealPath(),
            );
        } catch (\Throwable $exception) {
            Log::warning('Odoo CRM Excel import failed.', ['error' => $exception->getMessage()]);

            return response()->json([
                'message' => 'Odoo CRM Excel import failed: '.$exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $result,
            'message' => 'Odoo CRM Excel import completed.',
        ]);
    }

    public function syncEmployees(SyncOdooEmployees $syncOdooEmployees, OdooClient $odoo): JsonResponse
    {
        if (! $odoo->configured()) {
            return response()->json([
                'data' => ['synced' => 0, 'created' => 0, 'updated' => 0, 'pushed' => 0],
                'message' => 'Odoo is not configured.',
            ], 422);
        }

        try {
            $result = $syncOdooEmployees->handle();
        } catch (\Throwable $exception) {
            Log::warning('Odoo employee sync failed.', ['error' => $exception->getMessage()]);

            return response()->json([
                'message' => 'Odoo employee sync failed: '.$exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'data' => $result,
            'message' => 'Odoo employees synced.',
        ]);
    }

    public function quotations(Request $request, OdooClient $odoo): JsonResponse
    {
        if (! $odoo->configured()) {
            return response()->json(['data' => [], 'message' => 'Odoo is not configured.'], 422);
        }

        try {
            $partner = $request->integer('partner');
            $items = $odoo->listQuotations(
                min((int) $request->integer('limit', 100), 200),
                max((int) $request->integer('offset', 0), 0),
                $partner > 0 ? $partner : null,
            );
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'Odoo quotations failed: '.$exception->getMessage()], 502);
        }

        return response()->json(['data' => $items, 'message' => 'ok']);
    }

    public function invoices(Request $request, OdooClient $odoo): JsonResponse
    {
        if (! $odoo->configured()) {
            return response()->json(['data' => [], 'message' => 'Odoo is not configured.'], 422);
        }

        try {
            $partner = $request->integer('partner');
            $items = $odoo->listInvoices(
                min((int) $request->integer('limit', 100), 200),
                max((int) $request->integer('offset', 0), 0),
                $partner > 0 ? $partner : null,
            );
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'Odoo invoices failed: '.$exception->getMessage()], 502);
        }

        return response()->json(['data' => $items, 'message' => 'ok']);
    }

    public function storeQuotation(StoreOdooQuotationRequest $request, OdooClient $odoo): JsonResponse
    {
        if (! $odoo->configured()) {
            return response()->json(['message' => 'Odoo is not configured.'], 422);
        }

        $client = Client::query()->findOrFail($request->integer('client_id'));
        if (! filled($client->odoo_partner_id)) {
            return response()->json(['message' => 'العميل غير مربوط بشريك في أودو.'], 422);
        }

        try {
            $created = $odoo->createStaffQuotation(
                (string) $client->odoo_partner_id,
                filled($client->company_name) ? (string) $client->company_name : (string) $client->name,
                $client->email,
                $client->phone,
                $request->validated('lines'),
                $request->validated('notes'),
                $request->validated('reference'),
                filled($client->odoo_lead_id) ? $client->odoo_lead_id : null,
            );
        } catch (\Throwable $exception) {
            Log::warning('Odoo quotation create failed.', ['error' => $exception->getMessage()]);

            return response()->json([
                'message' => 'تعذر إنشاء عرض السعر في أودو: '.$exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'data' => $created,
            'message' => 'تم إنشاء عرض السعر في أودو.',
        ], 201);
    }

    public function storeInvoice(StoreOdooInvoiceRequest $request, OdooClient $odoo): JsonResponse
    {
        if (! $odoo->configured()) {
            return response()->json(['message' => 'Odoo is not configured.'], 422);
        }

        $client = Client::query()->findOrFail($request->integer('client_id'));
        if (! filled($client->odoo_partner_id)) {
            return response()->json(['message' => 'العميل غير مربوط بشريك في أودو.'], 422);
        }

        $lines = $request->validated('lines') ?? [];
        $reference = $request->validated('reference');
        $origin = null;
        $quotationId = $request->integer('quotation_id');

        try {
            if ($quotationId > 0) {
                $link = $odoo->quotationLink($quotationId);
                if ($link === null) {
                    return response()->json(['message' => 'عرض السعر غير موجود في أودو.'], 422);
                }
                if ((string) $link['partner_id'] !== (string) $client->odoo_partner_id) {
                    return response()->json(['message' => 'عرض السعر يخص عميلاً آخر في أودو.'], 422);
                }
                $origin = $link['name'] !== '' ? $link['name'] : null;
                $reference = filled($reference) ? $reference : $link['client_order_ref'];
                if ($lines === []) {
                    if ($link['amount_total'] <= 0) {
                        return response()->json(['message' => 'أضف بنود الفاتورة.'], 422);
                    }
                    $lines = [[
                        'title' => $origin ?: 'فاتورة',
                        'amount' => $link['amount_total'],
                        'units' => 1,
                    ]];
                }
            }

            $created = $odoo->createStaffInvoice(
                (string) $client->odoo_partner_id,
                $lines,
                $reference,
                $origin,
            );
        } catch (\Throwable $exception) {
            Log::warning('Odoo invoice create failed.', ['error' => $exception->getMessage()]);

            return response()->json([
                'message' => 'تعذر إنشاء الفاتورة في أودو: '.$exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'data' => $created,
            'message' => 'تم إنشاء الفاتورة في أودو.',
        ], 201);
    }
}
