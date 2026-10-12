<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ImportOdooCrmClients;
use App\Actions\ImportOdooCrmClientsFromExcel;
use App\Actions\ApplyQuotationAcceptance;
use App\Actions\ApproveQuotation;
use App\Actions\OpenRequestFromStaffInvoice;
use App\Actions\PublishOdooPaper;
use App\Actions\SendQuotation;
use App\Actions\SyncOdooEmployees;
use App\Actions\SyncOdooPartners;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportOdooCrmClientsExcelRequest;
use App\Http\Requests\StoreOdooInvoiceRequest;
use App\Http\Requests\StoreOdooQuotationRequest;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\RequestStatusTransitionService;
use App\Services\TelegramNotifier;
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

    public function storeQuotation(
        StoreOdooQuotationRequest $request,
        OdooClient $odoo,
        PublishOdooPaper $publish,
        OpenRequestFromStaffInvoice $openRequest,
        SendQuotation $sendQuotation,
        TelegramNotifier $telegram,
    ): JsonResponse {
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
        $opened = null;
        if ($action === 'send') {
            // A sent quotation opens a request and follows the bot quotation flow: the
            // client approves or rejects it, then payment and the work follow.
            $lines = $request->validated('lines');
            $opened = $openRequest->handle($client, $created, $lines, is_string($reference) ? $reference : null, 'quotation');
            try {
                ['snapshot' => $created, 'pdf_path' => $pdfPath] = $publish->prepareQuotation(
                    $created,
                    $request->validated('date_order'),
                    $request->validated('validity_date'),
                );
                $priced = $this->pricedLines($lines);
                $chatId = $client->telegram_user_id;
                $onBot = filled($chatId) && $telegram->canReachClient($chatId);
                $sendQuotation->handle(
                    $opened,
                    (float) collect($priced)->sum(fn (array $line): float => (float) $line['amount'] * (float) ($line['units'] ?? 1) * (1 - min(100, max(0, (float) ($line['discount'] ?? 0))) / 100)),
                    $request->validated('notes'),
                    'admin',
                    null,
                    $priced,
                    false,
                    null,
                    ['odoo_quotation_id' => $created['id'] ?? null, 'pdf_path' => $pdfPath],
                    $onBot ? null : (string) $request->validated('deliver'),
                );
                $where = $onBot ? 'على البوت مع زرّي الموافقة والرفض' : 'بالبريد أو واتساب، وسجّل قرار العميل من صفحة الطلب';
                $message = $sendQuotation->deliveredToClient
                    ? "أُرسل عرض السعر {$where}. الطلب {$opened->number} بانتظار موافقة العميل."
                    : "فُتح الطلب {$opened->number} بانتظار موافقة العميل، وتعذر إيصال عرض السعر. أعد إرساله من صفحة الطلب.";
            } catch (\Throwable $exception) {
                Log::warning('Odoo quotation send failed.', ['error' => $exception->getMessage()]);
                $message = "فُتح الطلب {$opened->number} وتعذر إرسال عرض السعر: ".$exception->getMessage();
            }
        }

        return response()->json([
            'data' => $created,
            'request' => $opened === null ? null : ['id' => $opened->id, 'number' => $opened->number, 'status' => $opened->fresh()?->status->value],
            'message' => $message,
        ], 201);
    }

    /**
     * Section and note rows are layout in Odoo; the request quotation keeps priced lines.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{title: string, amount: float, units: float, discount: float}>
     */
    private function pricedLines(array $lines): array
    {
        return array_values(array_map(fn (array $line): array => [
            'title' => (string) $line['title'],
            'amount' => (float) ($line['amount'] ?? 0),
            'units' => (float) ($line['units'] ?? 1),
            'discount' => (float) ($line['discount'] ?? 0),
        ], array_filter($lines, fn (array $line): bool => ! in_array($line['display_type'] ?? null, ['line_section', 'line_note'], true))));
    }

    public function storeInvoice(
        StoreOdooInvoiceRequest $request,
        OdooClient $odoo,
        PublishOdooPaper $publish,
        OpenRequestFromStaffInvoice $openRequest,
        ApproveQuotation $approve,
        ApplyQuotationAcceptance $acceptance,
        RequestStatusTransitionService $transitions,
        TelegramNotifier $telegram,
    ): JsonResponse {
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
            // An invoice from a quotation the client already has continues that request.
            $opened = $quotationId > 0
                ? ServiceRequest::query()->where('client_id', $client->id)->where('odoo_quotation_id', (string) $quotationId)->latest('id')->first()
                : null;
            $opened ??= $openRequest->handle($client, $created, $lines, is_string($reference) ? $reference : null);
            try {
                $chatId = $client->telegram_user_id;
                $onBot = filled($chatId) && $telegram->canReachClient($chatId);
                $created = $publish->invoice(
                    $client,
                    $opened,
                    $created,
                    (string) $request->validated('deliver'),
                    true,
                    $request->validated('invoice_date'),
                    $request->validated('due_date'),
                    deliver: ! $onBot,
                );
                $this->recordInvoice($opened, $created, $publish->pdfPath);
                if ($onBot) {
                    $publish->delivered = $this->sendInvoiceOnBot($telegram, $opened, $created, $publish->pdfPath);
                }
                // Payment steps follow on the bot: QR, then the client's receipt.
                $opened = $this->awaitPayment($opened->refresh(), $approve, $acceptance, $transitions);
                $clickup = $opened->clickupTasks()->where('task_type', 'sales')->exists()
                    ? ' ومهمة المبيعات في ClickUp.'
                    : '.';
                $message = $publish->delivered
                    ? 'تم ترحيل الفاتورة، والطلب '.$opened->number.' بانتظار الدفع'.$clickup
                    : 'رُحّلت الفاتورة والطلب '.$opened->number.' بانتظار الدفع، وتعذر إيصالها إلى البريد أو واتساب.';
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

    /**
     * The posted Odoo invoice on the request, so it shows with the request and later
     * payments go on it.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function recordInvoice(ServiceRequest $request, array $snapshot, string $pdfPath): void
    {
        $odooId = (string) ($snapshot['id'] ?? '');
        if ($odooId === '' || $request->invoices()->where('odoo_invoice_id', $odooId)->exists()) {
            return;
        }
        $request->invoices()->create([
            'invoice_number' => (string) ($snapshot['name'] ?? 'INV-'.$request->number),
            'amount' => (float) ($snapshot['amount_total'] ?? $request->amount_total ?? 0),
            'kind' => 'full',
            'status' => 'issued',
            'pdf_path' => $pdfPath !== '' ? $pdfPath : null,
            'issued_at' => now(),
            'odoo_invoice_id' => $odooId,
        ]);
    }

    /** @param  array<string, mixed>  $snapshot */
    private function sendInvoiceOnBot(TelegramNotifier $telegram, ServiceRequest $request, array $snapshot, string $pdfPath): bool
    {
        $name = (string) ($snapshot['name'] ?? '');
        $caption = trim("الفاتورة {$name} للطلب #{$request->number}")."\n"
            .'المبلغ: '.\App\Support\Money::format((float) ($snapshot['amount_total'] ?? $request->amount_total ?? 0))."\n"
            .'بعد التحويل ابعت صورة الإيصال هون ليتأكد الدفع ونكمل الطلب.';
        try {
            if ($pdfPath !== '') {
                return $telegram->sendStoredDocument($request, $pdfPath, $caption) !== null;
            }
            $telegram->send((string) $request->client?->telegram_user_id, $caption);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Dashboard invoice did not reach the bot.', ['request' => $request->number, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * A posted invoice means the work is agreed: a sent quotation counts as approved,
     * and a fresh request skips straight to payment. Later stages are left alone.
     */
    private function awaitPayment(
        ServiceRequest $request,
        ApproveQuotation $approve,
        ApplyQuotationAcceptance $acceptance,
        RequestStatusTransitionService $transitions,
    ): ServiceRequest {
        try {
            if ($request->status === RequestStatus::QuotationSent) {
                return $approve->handle($request, null, 'admin');
            }
            if (in_array($request->status, [RequestStatus::Submitted, RequestStatus::QuotationRejected], true)) {
                if ($request->status === RequestStatus::QuotationRejected) {
                    $request = $transitions->transition($request, RequestStatus::QuotationSent, 'admin', 'Invoice issued after a rejected quotation.');
                }
                $request = $transitions->transition($request, RequestStatus::AwaitingPayment, 'admin', 'Invoice issued from the dashboard.');

                return $acceptance->handle($request->fresh(['client', 'pricingPackage']) ?? $request);
            }
        } catch (\Throwable $exception) {
            Log::warning('Invoice could not move the request to payment.', ['request' => $request->number, 'error' => $exception->getMessage()]);
        }

        return $request->fresh() ?? $request;
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
