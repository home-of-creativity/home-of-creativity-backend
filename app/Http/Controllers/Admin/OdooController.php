<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ApplyQuotationAcceptance;
use App\Actions\ApproveQuotation;
use App\Actions\ImportOdooCrmClients;
use App\Actions\ImportOdooCrmClientsFromExcel;
use App\Actions\NotifyClientChannels;
use App\Actions\OpenRequestFromStaffInvoice;
use App\Actions\PublishOdooPaper;
use App\Actions\SendQuotation;
use App\Actions\SyncOdooEmployees;
use App\Actions\SyncOdooPartners;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportOdooCrmClientsExcelRequest;
use App\Http\Requests\StoreOdooInvoiceRequest;
use App\Http\Requests\StoreOdooQuotationRequest;
use App\Models\Client;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\RequestStatusTransitionService;
use App\Services\TelegramNotifier;
use App\Support\Money;
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

        return response()->json(['data' => $this->withRequests($items, 'odoo_quotation_id'), 'message' => 'ok']);
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

        return response()->json(['data' => $this->withRequests($items, 'odoo_invoice_id'), 'message' => 'ok']);
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

    public function showQuotation(int $id, OdooClient $odoo): JsonResponse
    {
        $snapshot = $this->paper($odoo, 'sale.order', $id);

        return response()->json(['data' => [...$this->withRequests([$snapshot], 'odoo_quotation_id')[0], 'lines' => $odoo->paperLines('sale.order', $id)]]);
    }

    public function showInvoice(int $id, OdooClient $odoo): JsonResponse
    {
        $snapshot = $this->paper($odoo, 'account.move', $id);

        return response()->json(['data' => [...$this->withRequests([$snapshot], 'odoo_invoice_id')[0], 'lines' => $odoo->paperLines('account.move', $id)]]);
    }

    /**
     * A quotation the client has not approved yet. A sent one reaches the client again as
     * a new version, with the approve and reject buttons on the bot.
     */
    public function updateQuotation(
        Request $request,
        int $id,
        OdooClient $odoo,
        PublishOdooPaper $publish,
        SendQuotation $sendQuotation,
        TelegramNotifier $telegram,
    ): JsonResponse {
        $validated = $request->validate($this->paperRules() + [
            'notes' => ['nullable', 'string', 'max:5000'],
            'deliver' => ['nullable', 'in:email,whatsapp,both,phone'],
        ]);
        $snapshot = $this->paper($odoo, 'sale.order', $id);
        abort_unless(in_array($snapshot['state'] ?? '', ['draft', 'sent'], true), 422, 'عرض السعر أصبح أمر بيع أو ملغى، فلا يُعدَّل.');

        try {
            $updated = $odoo->updateStaffQuotation($id, $validated['lines'], $validated['notes'] ?? null, $validated['reference'] ?? null);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'تعذر تعديل عرض السعر في أودو: '.$exception->getMessage()], 502);
        }

        $linked = ServiceRequest::query()->with('client')->where('odoo_quotation_id', (string) $id)->latest('id')->first();
        $message = 'عُدّل عرض السعر في أودو.';
        if ($linked !== null && $linked->status === RequestStatus::QuotationSent && $linked->client !== null) {
            $priced = $this->pricedLines($validated['lines']);
            $amount = $this->linesTotal($priced);
            ['snapshot' => $updated, 'pdf_path' => $pdfPath] = $publish->prepareQuotation($updated, null, null);
            $chatId = $linked->client->telegram_user_id;
            $onBot = filled($chatId) && $telegram->canReachClient($chatId);
            $linked->forceFill(['amount_total' => $amount, 'amount_remaining' => $amount])->save();
            $sendQuotation->handle(
                $linked,
                $amount,
                $validated['notes'] ?? null,
                'admin',
                null,
                $priced,
                true,
                null,
                ['odoo_quotation_id' => $id, 'pdf_path' => $pdfPath],
                $onBot ? null : (string) ($validated['deliver'] ?? $this->defaultChannel($linked->client)),
            );
            $message = $sendQuotation->deliveredToClient
                ? "عُدّل عرض السعر وأُعيد إرساله للعميل. الطلب {$linked->number} ما زال بانتظار موافقته."
                : "عُدّل عرض السعر وتعذر إعادة إرساله للعميل. الطلب {$linked->number} بانتظار موافقته.";
        }

        return response()->json(['data' => $updated, 'message' => $message]);
    }

    /** A quotation not approved yet: cancelled in Odoo (a draft is deleted) and its request closed. */
    public function destroyQuotation(int $id, OdooClient $odoo, RequestStatusTransitionService $transitions, NotifyClientChannels $notify): JsonResponse
    {
        $snapshot = $this->paper($odoo, 'sale.order', $id);
        if (($snapshot['state'] ?? '') === 'sale') {
            return $this->cancelSalesOrder($id, $snapshot, $odoo, $transitions, $notify);
        }
        abort_unless(in_array($snapshot['state'] ?? '', ['draft', 'sent', 'cancel'], true), 422, 'عرض السعر أصبح أمر بيع، فلا يُحذف من هنا.');

        try {
            $result = $odoo->removeQuotation($id);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'تعذر إلغاء عرض السعر في أودو: '.$exception->getMessage()], 502);
        }

        $message = $result === 'deleted' ? 'حُذف عرض السعر من أودو.' : 'أُلغي عرض السعر في أودو.';
        $linked = ServiceRequest::query()->with('client')->where('odoo_quotation_id', (string) $id)->latest('id')->first();
        if ($linked !== null && in_array($linked->status, [RequestStatus::Submitted, RequestStatus::QuotationSent, RequestStatus::QuotationRejected], true)) {
            $transitions->transition($linked, RequestStatus::Cancelled, 'admin', 'Quotation cancelled from the dashboard.');
            $name = (string) ($snapshot['name'] ?? '');
            $notify->send($linked->fresh(['client']) ?? $linked, "أُلغي عرض السعر {$name} للطلب #{$linked->number}. إذا عندك أي سؤال تواصل معنا.");
            $message .= " وأُلغي الطلب {$linked->number}.";
        }

        return response()->json(['data' => ['id' => $id, 'result' => $result], 'message' => $message]);
    }

    /**
     * Sales cancel a confirmed sales order: its unpaid invoices go first, then the order,
     * and a request still waiting for payment is cancelled. Anything paid stops it.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function cancelSalesOrder(int $id, array $snapshot, OdooClient $odoo, RequestStatusTransitionService $transitions, NotifyClientChannels $notify): JsonResponse
    {
        $linked = ServiceRequest::query()->with('client')->where('odoo_quotation_id', (string) $id)->latest('id')->first();
        abort_if($linked !== null && (float) ($linked->amount_paid ?? 0) > 0.009, 422, 'دُفع جزء من هذا الطلب، فلا يُلغى أمر البيع من هنا.');

        try {
            $cancelled = $odoo->cancelSalesOrder($id);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'تعذر إلغاء أمر البيع في أودو: '.$exception->getMessage()], 502);
        }
        abort_if($cancelled === null, 422, 'على أمر البيع فاتورة مدفوعة، فلا يُلغى.');

        $message = $cancelled === [] ? 'أُلغي أمر البيع في أودو.' : 'أُلغي أمر البيع في أودو مع فواتيره غير المدفوعة.';
        $open = [RequestStatus::Submitted, RequestStatus::QuotationSent, RequestStatus::QuotationRejected, RequestStatus::AwaitingPayment];
        if ($linked !== null && in_array($linked->status, $open, true)) {
            $invoiceIds = array_map('strval', $cancelled);
            $linked->invoices()->whereIn('odoo_invoice_id', $invoiceIds)->update(['status' => 'cancelled']);
            if (in_array((string) $linked->odoo_invoice_id, $invoiceIds, true)) {
                $linked->forceFill(['odoo_invoice_id' => null])->save();
            }
            $transitions->transition($linked, RequestStatus::Cancelled, 'admin', 'Sales order cancelled from the dashboard.');
            $name = (string) ($snapshot['name'] ?? '');
            $notify->send($linked->fresh(['client']) ?? $linked, "أُلغي أمر البيع {$name} للطلب #{$linked->number}. إذا عندك أي سؤال تواصل معنا.");
            $message .= " وأُلغي الطلب {$linked->number}.";
        }

        return response()->json(['data' => ['id' => $id, 'result' => 'cancelled'], 'message' => $message]);
    }

    /** An unpaid invoice: corrected in Odoo, posted again, and sent to the client again. */
    public function updateInvoice(
        Request $request,
        int $id,
        OdooClient $odoo,
        PublishOdooPaper $publish,
        TelegramNotifier $telegram,
    ): JsonResponse {
        $validated = $request->validate($this->paperRules() + [
            'deliver' => ['nullable', 'in:email,whatsapp,both,phone'],
        ]);
        $snapshot = $this->paper($odoo, 'account.move', $id);
        abort_if(($snapshot['state'] ?? '') === 'cancel', 422, 'الفاتورة ملغاة.');
        abort_unless(in_array($snapshot['payment_state'] ?? 'not_paid', ['not_paid'], true), 422, 'دُفعت الفاتورة كلها أو بعضها، فلا تُعدَّل.');

        try {
            $updated = $odoo->updateStaffInvoice($id, $validated['lines'], $validated['reference'] ?? null);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'تعذر تعديل الفاتورة في أودو: '.$exception->getMessage()], 502);
        }

        $linked = ServiceRequest::query()->with('client')->where('odoo_invoice_id', (string) $id)->latest('id')->first();
        $message = 'عُدّلت الفاتورة في أودو.';
        if ($linked !== null && $linked->client !== null && ($updated['state'] ?? '') === 'posted') {
            $amount = (float) ($updated['amount_total'] ?? 0);
            $linked->forceFill(['amount_total' => $amount, 'amount_remaining' => max(0, $amount - (float) $linked->amount_paid), 'quotation_amount' => $amount])->save();
            $chatId = $linked->client->telegram_user_id;
            $onBot = filled($chatId) && $telegram->canReachClient($chatId);
            $updated = $publish->invoice($linked->client, $linked, $updated, (string) ($validated['deliver'] ?? $this->defaultChannel($linked->client)), false, null, null, deliver: ! $onBot);
            $linked->invoices()->where('odoo_invoice_id', (string) $id)->update([
                'amount' => $amount,
                'pdf_path' => $publish->pdfPath !== '' ? $publish->pdfPath : null,
                'status' => 'issued',
            ]);
            $delivered = $onBot ? $this->sendInvoiceOnBot($telegram, $linked, $updated, $publish->pdfPath) : $publish->delivered;
            $message = $delivered
                ? "عُدّلت الفاتورة وأُعيد إرسالها للعميل. الطلب {$linked->number} بانتظار الدفع."
                : "عُدّلت الفاتورة وتعذر إعادة إرسالها للعميل. الطلب {$linked->number} بانتظار الدفع.";
        }

        return response()->json(['data' => $updated, 'message' => $message]);
    }

    /** An unpaid invoice is cancelled in Odoo; its request keeps waiting for payment until a correct one is issued. */
    public function destroyInvoice(int $id, OdooClient $odoo, NotifyClientChannels $notify): JsonResponse
    {
        $snapshot = $this->paper($odoo, 'account.move', $id);
        abort_unless(in_array($snapshot['payment_state'] ?? 'not_paid', ['not_paid'], true), 422, 'دُفعت الفاتورة كلها أو بعضها، فلا تُلغى من هنا.');

        try {
            $odoo->cancelInvoice($id);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'تعذر إلغاء الفاتورة في أودو: '.$exception->getMessage()], 502);
        }

        $message = 'أُلغيت الفاتورة في أودو.';
        $linked = ServiceRequest::query()->with('client')->where('odoo_invoice_id', (string) $id)->latest('id')->first();
        if ($linked !== null) {
            $linked->invoices()->where('odoo_invoice_id', (string) $id)->update(['status' => 'cancelled']);
            $linked->forceFill(['odoo_invoice_id' => null])->save();
            if (($snapshot['state'] ?? '') === 'posted') {
                $name = (string) ($snapshot['name'] ?? '');
                $notify->send($linked, "أُلغيت الفاتورة {$name} للطلب #{$linked->number}، وستصلك فاتورة صحيحة بدلاً منها.");
            }
            $message .= " الطلب {$linked->number} ما زال بانتظار الدفع حتى تصدر فاتورة صحيحة.";
        }

        return response()->json(['data' => ['id' => $id], 'message' => $message]);
    }

    /**
     * The Odoo quotation or invoice behind an edit, with a clear answer when Odoo is
     * off, unreachable, or the paper is gone.
     *
     * @return array<string, mixed>
     */
    private function paper(OdooClient $odoo, string $model, int $id): array
    {
        abort_unless($odoo->configured(), 422, 'Odoo is not configured.');
        try {
            $snapshot = $model === 'sale.order' ? $odoo->quotationSnapshot($id) : $odoo->invoiceSnapshot($id);
        } catch (\Throwable $exception) {
            abort(502, 'تعذر الوصول إلى أودو: '.$exception->getMessage());
        }

        return $snapshot ?? abort(404, $model === 'sale.order' ? 'عرض السعر غير موجود في أودو.' : 'الفاتورة غير موجودة في أودو.');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withRequests(array $items, string $column): array
    {
        $ids = array_values(array_filter(array_map(fn (array $item): string => (string) ($item['id'] ?? ''), $items)));
        $requests = $ids === [] ? collect() : ServiceRequest::query()->whereIn($column, $ids)->latest('id')->get(['id', 'number', 'status', $column])->unique($column)->keyBy($column);

        return array_map(function (array $item) use ($requests): array {
            $linked = $requests->get((string) ($item['id'] ?? ''));

            return [...$item, 'request' => $linked === null ? null : ['id' => $linked->id, 'number' => $linked->number, 'status' => $linked->status->value]];
        }, $items);
    }

    /** @return array<string, list<mixed>> */
    private function paperRules(): array
    {
        return [
            'reference' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.title' => ['required', 'string', 'max:255'],
            'lines.*.display_type' => ['nullable', 'in:line_section,line_note'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0', 'required_unless:lines.*.display_type,line_section,line_note'],
            'lines.*.units' => ['nullable', 'numeric', 'min:0.01'],
            'lines.*.product_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function defaultChannel(Client $client): string
    {
        return filled($client->email) ? (filled($client->phone) ? 'both' : 'email') : 'whatsapp';
    }

    /** @param  list<array{amount: float, units: float, discount: float}>  $lines */
    private function linesTotal(array $lines): float
    {
        return (float) collect($lines)->sum(fn (array $line): float => $line['amount'] * $line['units'] * (1 - min(100, max(0, $line['discount'])) / 100));
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
            .'المبلغ: '.Money::format((float) ($snapshot['amount_total'] ?? $request->amount_total ?? 0))."\n"
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
