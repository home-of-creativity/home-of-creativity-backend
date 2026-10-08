<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ImportOdooCrmClients;
use App\Actions\ImportOdooCrmClientsFromExcel;
use App\Actions\OpenRequestFromStaffInvoice;
use App\Actions\PublishOdooPaper;
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

    public function products(OdooClient $odoo): JsonResponse
    {
        if (! $odoo->configured()) {
            return response()->json(['message' => 'Odoo is not configured.'], 422);
        }

        try {
            return response()->json(['data' => $odoo->listSaleProducts(), 'message' => 'ok']);
        } catch (\Throwable $exception) {
            Log::warning('Odoo products failed.', ['error' => $exception->getMessage()]);

            return response()->json(['message' => 'تعذر قراءة منتجات أودو.'], 502);
        }
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

    public function storeQuotation(StoreOdooQuotationRequest $request, OdooClient $odoo, PublishOdooPaper $publish): JsonResponse
    {
        if (! $odoo->configured()) {
            return response()->json(['message' => 'Odoo is not configured.'], 422);
        }

        $client = Client::query()->findOrFail($request->integer('client_id'));
        if (! filled($client->odoo_partner_id)) {
            return response()->json(['message' => 'العميل غير مربوط بشريك في أودو.'], 422);
        }

        $action = (string) ($request->validated('action') ?: 'draft');
        if ($action === 'send') {
            $blocked = $this->paperChannel($client, (string) $request->validated('deliver'));
            if ($blocked instanceof JsonResponse) {
                return $blocked;
            }
        }

        $reference = $request->validated('reference');

        try {
            $created = $odoo->createStaffQuotation(
                (string) $client->odoo_partner_id,
                filled($client->company_name) ? (string) $client->company_name : (string) $client->name,
                $client->email,
                $client->phone,
                $request->validated('lines'),
                $request->validated('notes'),
                $reference,
                filled($client->odoo_lead_id) ? $client->odoo_lead_id : null,
            );
        } catch (\Throwable $exception) {
            Log::warning('Odoo quotation create failed.', ['error' => $exception->getMessage()]);

            return response()->json([
                'message' => 'تعذر إنشاء عرض السعر في أودو: '.$exception->getMessage(),
            ], 502);
        }

        $message = 'تم حفظ عرض السعر.';
        if ($action === 'send') {
            try {
                $created = $publish->quotation(
                    $client,
                    $created,
                    (string) $request->validated('deliver'),
                    $request->validated('date_order'),
                    $request->validated('validity_date'),
                );
                $message = $publish->delivered
                    ? 'تم إرسال عرض السعر.'
                    : 'أُرسل عرض السعر في أودو وتعذر إيصاله إلى البريد أو واتساب.';
            } catch (\Throwable $exception) {
                Log::warning('Odoo quotation send failed.', ['error' => $exception->getMessage()]);
                $message = 'أُنشئ عرض السعر في أودو وتعذر إرساله.';
            }
        }

        return response()->json([
            'data' => $created,
            'message' => $message,
        ], 201);
    }

    public function storeInvoice(StoreOdooInvoiceRequest $request, OdooClient $odoo, PublishOdooPaper $publish, OpenRequestFromStaffInvoice $openRequest): JsonResponse
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
        $quotationId = $request->integer('quotation_id');
        $action = (string) ($request->validated('action') ?: 'draft');
        if ($action === 'post') {
            $blocked = $this->paperChannel($client, (string) $request->validated('deliver'));
            if ($blocked instanceof JsonResponse) {
                return $blocked;
            }
        }

        try {
            if ($quotationId > 0) {
                $link = $odoo->quotationLink($quotationId);
                if ($link === null) {
                    return response()->json(['message' => 'عرض السعر غير موجود في أودو.'], 422);
                }
                if ((string) $link['partner_id'] !== (string) $client->odoo_partner_id) {
                    return response()->json(['message' => 'عرض السعر يخص عميلاً آخر في أودو.'], 422);
                }
                if ($link['state'] === 'cancel') {
                    return response()->json(['message' => 'عرض السعر ملغى في أودو.'], 422);
                }

                $created = $odoo->invoiceFromSaleOrder($link);
            } else {
                $created = $odoo->createStaffInvoice(
                    (string) $client->odoo_partner_id,
                    $lines,
                    $reference,
                    null,
                );
            }
        } catch (\Throwable $exception) {
            Log::warning('Odoo invoice create failed.', ['error' => $exception->getMessage()]);

            return response()->json([
                'message' => 'تعذر إنشاء الفاتورة في أودو: '.$exception->getMessage(),
            ], 502);
        }

        $message = 'تم حفظ الفاتورة كمسودة.';
        if ($action === 'post') {
            $opened = $openRequest->handle($client, $created, $lines, is_string($reference) ? $reference : null);
            try {
                $created = $publish->invoice(
                    $client,
                    $opened,
                    $created,
                    (string) $request->validated('deliver'),
                    true,
                    $request->validated('invoice_date'),
                    $request->validated('due_date'),
                );
                $opened->refresh();
                $clickup = $opened->clickupTasks()->where('task_type', 'sales')->exists()
                    ? ' ومهمة المبيعات في ClickUp.'
                    : '.';
                $message = $publish->delivered
                    ? 'تم ترحيل الفاتورة وفتح الطلب '.$opened->number.$clickup
                    : 'رُحّلت الفاتورة وفُتح الطلب '.$opened->number.' وتعذر إيصالها إلى البريد أو واتساب.';
            } catch (\Throwable $exception) {
                Log::warning('Odoo invoice post failed.', ['error' => $exception->getMessage()]);
                $message = 'أُنشئت الفاتورة وفُتح الطلب '.$opened->number.' وتعذر ترحيلها أو إرسالها.';
            }
        }

        return response()->json([
            'data' => $created,
            'message' => $message,
        ], 201);
    }

    private function paperChannel(Client $client, string $channel): ?JsonResponse
    {
        $channels = match ($channel) {
            'both' => ['email', 'whatsapp'],
            'phone', 'whatsapp' => ['whatsapp'],
            'email' => ['email'],
            default => [],
        };
        if ($channels === []) {
            return response()->json(['message' => 'اختر البريد أو واتساب.'], 422);
        }
        if (in_array('email', $channels, true) && ! filled($client->email)) {
            return response()->json(['message' => 'العميل بلا بريد إلكتروني.'], 422);
        }
        if (in_array('whatsapp', $channels, true) && ! filled($client->phone)) {
            return response()->json(['message' => 'العميل بلا رقم واتساب.'], 422);
        }

        return null;
    }
}
