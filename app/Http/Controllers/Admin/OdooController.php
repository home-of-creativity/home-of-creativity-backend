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
use App\Models\Invoice;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\RequestStatusTransitionService;
use App\Services\TelegramNotifier;
use App\Support\Money;
use App\Support\OdooPaperLimits;
use App\Support\ResolveOdooClient;
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

    public function taxes(OdooClient $odoo): JsonResponse
    {
        if (! $odoo->configured()) {
            return response()->json(['message' => 'Odoo is not configured.'], 422);
        }

        try {
            return response()->json(['data' => $odoo->listSaleTaxes(), 'message' => 'ok']);
        } catch (\Throwable $exception) {
            Log::warning('Odoo taxes failed.', ['error' => $exception->getMessage()]);

            return response()->json(['message' => 'تعذر قراءة ضرائب أودو.'], 502);
        }
    }

    public function paymentTerms(OdooClient $odoo): JsonResponse
    {
        if (! $odoo->configured()) {
            return response()->json(['message' => 'Odoo is not configured.'], 422);
        }

        try {
            return response()->json(['data' => $odoo->listPaymentTerms(), 'message' => 'ok']);
        } catch (\Throwable $exception) {
            Log::warning('Odoo payment terms failed.', ['error' => $exception->getMessage()]);

            return response()->json(['message' => 'تعذر قراءة شروط السداد من أودو.'], 502);
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
                $this->paymentTerm($request->validated()),
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
                $priced = $this->pricedLines($lines, $created);
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

    public function showQuotation(int $id, OdooClient $odoo, ResolveOdooClient $resolve): JsonResponse
    {
        $snapshot = $this->paper($odoo, 'sale.order', $id);

        return response()->json(['data' => [
            ...$this->withRequests([$snapshot], 'odoo_quotation_id')[0],
            ...$this->paperClient($resolve, $snapshot, 'odoo_quotation_id'),
            'lines' => $odoo->paperLines('sale.order', $id),
            'notes' => $odoo->quotationNote($id),
            'invoiced' => $odoo->saleOrderInvoiced($id),
        ]]);
    }

    public function showInvoice(int $id, OdooClient $odoo, ResolveOdooClient $resolve): JsonResponse
    {
        $snapshot = $this->paper($odoo, 'account.move', $id);

        return response()->json(['data' => [
            ...$this->withRequests([$snapshot], 'odoo_invoice_id')[0],
            ...$this->paperClient($resolve, $snapshot, 'odoo_invoice_id'),
            'lines' => $odoo->paperLines('account.move', $id),
        ]]);
    }

    /**
     * The dashboard client of a paper: by its partner or company, else by the request it is on.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{client_id: int|null, client_name: string|null}
     */
    private function paperClient(ResolveOdooClient $resolve, array $snapshot, string $column): array
    {
        $client = $resolve->forPartner($snapshot['partner_id'] ?? null, $snapshot['commercial_partner_id'] ?? null)
            ?? ServiceRequest::query()->where($column, (string) ($snapshot['id'] ?? ''))->latest('id')->first()?->client;

        return [
            'client_id' => $client?->id,
            'client_name' => $client === null ? null : (filled($client->company_name) ? (string) $client->company_name : (string) $client->name),
        ];
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
            'notes' => OdooPaperLimits::notesRule(),
            'deliver' => ['nullable', 'in:email,whatsapp,both,phone'],
        ]);
        $snapshot = $this->paper($odoo, 'sale.order', $id);
        abort_unless(in_array($snapshot['state'] ?? '', ['draft', 'sent'], true), 422, 'عرض السعر أصبح أمر بيع أو ملغى، فلا يُعدَّل.');

        try {
            $updated = $odoo->updateStaffQuotation($id, $validated['lines'], $validated['notes'] ?? null, $validated['reference'] ?? null, $this->paymentTerm($validated));
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'تعذر تعديل عرض السعر في أودو: '.$exception->getMessage()], 502);
        }

        $linked = ServiceRequest::query()->with('client')->where('odoo_quotation_id', (string) $id)->latest('id')->first();
        $message = 'عُدّل عرض السعر في أودو.';
        if ($linked !== null && $linked->status === RequestStatus::QuotationSent && $linked->client !== null) {
            $priced = $this->pricedLines($validated['lines'], $updated);
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
        if ($linked !== null && in_array($linked->status, [RequestStatus::Submitted, RequestStatus::QuotationSent, RequestStatus::QuotationRejected, RequestStatus::AwaitingAdminApproval], true)) {
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
        $open = [RequestStatus::Submitted, RequestStatus::QuotationSent, RequestStatus::QuotationRejected, RequestStatus::AwaitingAdminApproval, RequestStatus::AwaitingPayment];
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
        $validated = $request->validate([
            'reference' => ['nullable', 'string', 'max:64'],
            'payment_term_id' => ['nullable', 'integer', 'min:0'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'deliver' => ['nullable', 'in:email,whatsapp,both,phone'],
        ] + OdooPaperLimits::lineRules('required_without:client_id'));
        $snapshot = $this->paper($odoo, 'account.move', $id);
        abort_if(($snapshot['state'] ?? '') === 'cancel', 422, 'الفاتورة ملغاة.');
        abort_unless(in_array($snapshot['payment_state'] ?? 'not_paid', ['not_paid'], true), 422, 'دُفعت الفاتورة كلها أو بعضها، فلا تُعدَّل.');

        $this->moveDraftToClient($odoo, $snapshot, $validated['client_id'] ?? null);
        try {
            $updated = isset($validated['lines'])
                ? $odoo->updateStaffInvoice($id, $validated['lines'], $validated['reference'] ?? null, $this->paymentTerm($validated))
                : ($odoo->invoiceSnapshot($id) ?? $snapshot);
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

    /**
     * A draft made out to the wrong partner moves to the chosen client. A posted invoice
     * keeps its customer, as in Odoo.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function moveDraftToClient(OdooClient $odoo, array $snapshot, mixed $clientId): void
    {
        if (! is_numeric($clientId) || (int) $clientId <= 0) {
            return;
        }
        $client = Client::query()->findOrFail((int) $clientId);
        abort_unless(filled($client->odoo_partner_id), 422, 'العميل غير مربوط بشريك في أودو.');
        if ((string) $client->odoo_partner_id === (string) ($snapshot['partner_id'] ?? '')) {
            return;
        }
        abort_unless(($snapshot['state'] ?? '') === 'draft', 422, 'عميل الفاتورة يتغير وهي مسودة فقط.');
        try {
            $odoo->setInvoicePartner((int) $snapshot['id'], (int) $client->odoo_partner_id);
        } catch (\Throwable $exception) {
            abort(502, 'تعذر تغيير عميل الفاتورة في أودو: '.$exception->getMessage());
        }
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
            'payment_term_id' => ['nullable', 'integer', 'min:0'],
        ] + OdooPaperLimits::lineRules();
    }

    /** The term the form sent: null leaves the paper's term, 0 clears it. */
    private function paymentTerm(array $validated): ?int
    {
        return array_key_exists('payment_term_id', $validated) ? (int) $validated['payment_term_id'] : null;
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
     * When a line carries Odoo taxes, a tax line brings the amount to the Odoo total.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>|null  $snapshot
     * @return list<array{title: string, amount: float, units: float, discount: float}>
     */
    private function pricedLines(array $lines, ?array $snapshot = null): array
    {
        $priced = array_values(array_map(fn (array $line): array => [
            'title' => (string) $line['title'],
            'amount' => (float) ($line['amount'] ?? 0),
            'units' => (float) ($line['units'] ?? 1),
            'discount' => (float) ($line['discount'] ?? 0),
        ], array_filter($lines, fn (array $line): bool => ! in_array($line['display_type'] ?? null, ['line_section', 'line_note'], true))));
        $taxed = collect($lines)->contains(fn (array $line): bool => ! empty($line['tax_ids']));
        $tax = round((float) ($snapshot['amount_total'] ?? 0) - $this->linesTotal($priced), 2);
        if ($taxed && $tax > 0.009) {
            $priced[] = ['title' => 'الضرائب', 'amount' => $tax, 'units' => 1.0, 'discount' => 0.0];
        }

        return $priced;
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
        ResolveOdooClient $resolve,
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
                if (! $resolve->belongsTo($client, $link['partner_id'])) {
                    return response()->json(['message' => 'عرض السعر يخص عميلاً آخر في أودو.'], 422);
                }
                if ($link['state'] === 'cancel') {
                    return response()->json(['message' => 'عرض السعر ملغى في أودو.'], 422);
                }

                $term = $this->paymentTerm($request->validated());
                if ($term !== null) {
                    $link['payment_term_id'] = $term > 0 ? $term : null;
                }
                $created = $odoo->invoiceFromSaleOrder($link);
            } else {
                $created = $odoo->createStaffInvoice(
                    (string) $client->odoo_partner_id,
                    $lines,
                    $reference,
                    null,
                    $this->paymentTerm($request->validated()),
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
            [$created, $message] = $this->postAndSend(
                $client,
                $opened,
                $created,
                (string) $request->validated('deliver'),
                $request->validated('invoice_date'),
                $request->validated('due_date'),
                $publish,
                $approve,
                $acceptance,
                $transitions,
                $telegram,
            );
        }

        return response()->json([
            'data' => $created,
            'message' => $message,
        ], 201);
    }

    /**
     * Odoo's "Create invoice" wizard on a quotation or sales order: a regular invoice of what
     * is left, or a down payment by percentage or fixed amount. The result is a draft linked
     * to the order, opened in the dashboard for review before it is confirmed.
     */
    public function invoiceQuotation(Request $request, int $id, OdooClient $odoo, ResolveOdooClient $resolve): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'in:delivered,percentage,fixed'],
            'amount' => ['nullable', 'required_unless:method,delivered', 'numeric', 'gt:0', 'max:100000000'],
        ]);
        if ($validated['method'] === 'percentage') {
            abort_if((float) $validated['amount'] > 100, 422, 'النسبة لا تتجاوز 100%.');
        }
        $snapshot = $this->paper($odoo, 'sale.order', $id);
        abort_if(($snapshot['state'] ?? '') === 'cancel', 422, 'عرض السعر ملغى في أودو.');

        try {
            $invoiceId = $odoo->invoiceSaleOrderWizard($id, $validated['method'], isset($validated['amount']) ? (float) $validated['amount'] : null);
        } catch (\Throwable $exception) {
            Log::warning('Odoo invoice wizard failed.', ['order' => $id, 'error' => $exception->getMessage()]);

            return response()->json(['message' => 'تعذر إنشاء الفاتورة في أودو: '.$exception->getMessage()], 502);
        }

        $invoice = $odoo->invoiceSnapshot($invoiceId) ?? ['id' => $invoiceId];
        $linked = $this->linkDraftToRequest($odoo, $id, $invoiceId);

        return response()->json([
            'data' => [
                ...$invoice,
                ...$this->paperClient($resolve, $invoice, 'odoo_invoice_id'),
                'lines' => $odoo->paperLines('account.move', $invoiceId),
                'quotation_id' => $id,
                'request' => $linked === null ? null : ['id' => $linked->id, 'number' => $linked->number, 'status' => $linked->status->value],
            ],
            'message' => 'أُنشئت مسودة الفاتورة. راجعها ثم اضغط «تأكيد» لترحيلها.',
        ], 201);
    }

    /**
     * The wizard draft becomes the request's invoice, so confirming the payment posts it
     * instead of making a second one. An invoice still waiting on its payment keeps its place.
     */
    private function linkDraftToRequest(OdooClient $odoo, int $orderId, int $invoiceId): ?ServiceRequest
    {
        $linked = ServiceRequest::query()->where('odoo_quotation_id', (string) $orderId)->latest('id')->first();
        if ($linked === null) {
            return null;
        }
        $current = (int) ($linked->odoo_invoice_id ?? 0);
        if ($current > 0 && $current !== $invoiceId) {
            try {
                $snapshot = $odoo->invoiceSnapshot($current);
            } catch (\Throwable) {
                return $linked;
            }
            $open = $snapshot !== null
                && ($snapshot['state'] ?? '') !== 'cancel'
                && ! in_array((string) ($snapshot['payment_state'] ?? ''), ['paid', 'in_payment', 'reversed'], true);
            if ($open) {
                return $linked;
            }
        }
        $linked->forceFill(['odoo_invoice_id' => (string) $invoiceId])->save();

        return $linked;
    }

    /**
     * «تأكيد» on a draft invoice: posted in Odoo, sent to the client, and the request waits
     * for payment, as when an invoice is created and posted at once.
     */
    public function postInvoice(
        Request $request,
        int $id,
        OdooClient $odoo,
        PublishOdooPaper $publish,
        OpenRequestFromStaffInvoice $openRequest,
        ApproveQuotation $approve,
        ApplyQuotationAcceptance $acceptance,
        RequestStatusTransitionService $transitions,
        TelegramNotifier $telegram,
        ResolveOdooClient $resolve,
    ): JsonResponse {
        $validated = $request->validate([
            'deliver' => ['required', 'in:email,whatsapp,both,phone'],
            'quotation_id' => ['nullable', 'integer', 'min:1'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
        ]);
        $snapshot = $this->paper($odoo, 'account.move', $id);
        abort_unless(($snapshot['state'] ?? '') === 'draft', 422, 'الفاتورة ليست مسودة.');
        if (isset($validated['client_id'])) {
            $this->moveDraftToClient($odoo, $snapshot, $validated['client_id']);
            $snapshot = $odoo->invoiceSnapshot($id) ?? $snapshot;
        }
        // A draft opened from the invoice list knows its order only by the origin name.
        $quotationId = (int) ($validated['quotation_id'] ?? $odoo->saleOrderIdByName($snapshot['invoice_origin'] ?? null) ?? 0);
        $client = $resolve->forPartner($snapshot['partner_id'] ?? null, $snapshot['commercial_partner_id'] ?? null)
            ?? ServiceRequest::query()->where('odoo_invoice_id', (string) $id)->latest('id')->first()?->client
            ?? ($quotationId > 0 ? ServiceRequest::query()->where('odoo_quotation_id', (string) $quotationId)->latest('id')->first()?->client : null)
            ?? ($quotationId > 0 ? $resolve->forPartner($odoo->quotationSnapshot($quotationId)['partner_id'] ?? null) : null);
        abort_if($client === null, 422, 'عميل الفاتورة غير مربوط بعميل في الداشبورد. اربط شريك أودو بالعميل أولاً.');
        $blocked = $this->paperChannel($client, $validated['deliver']);
        if ($blocked instanceof JsonResponse) {
            return $blocked;
        }

        $opened = ServiceRequest::query()->where('odoo_invoice_id', (string) $id)->latest('id')->first()
            ?? ($quotationId > 0 ? ServiceRequest::query()->where('client_id', $client->id)->where('odoo_quotation_id', (string) $quotationId)->latest('id')->first() : null)
            ?? $openRequest->handle(
                $client,
                $snapshot,
                [],
                is_string($snapshot['ref'] ?? null) ? $snapshot['ref'] : null,
                orderId: $quotationId > 0 ? $quotationId : null,
                orderTotal: $quotationId > 0 ? (float) ($odoo->quotationSnapshot($quotationId)['amount_total'] ?? 0) : null,
            );
        [$created, $message] = $this->postAndSend(
            $client,
            $opened,
            $snapshot,
            $validated['deliver'],
            $validated['invoice_date'] ?? null,
            $validated['due_date'] ?? null,
            $publish,
            $approve,
            $acceptance,
            $transitions,
            $telegram,
        );

        return response()->json(['data' => $created, 'message' => $message]);
    }

    /**
     * Posts the invoice, records it on the request, sends it (bot for bot clients, else email
     * or WhatsApp), and moves the request to awaiting payment.
     *
     * @param  array<string, mixed>  $created
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function postAndSend(
        Client $client,
        ServiceRequest $opened,
        array $created,
        string $deliver,
        ?string $invoiceDate,
        ?string $dueDate,
        PublishOdooPaper $publish,
        ApproveQuotation $approve,
        ApplyQuotationAcceptance $acceptance,
        RequestStatusTransitionService $transitions,
        TelegramNotifier $telegram,
    ): array {
        try {
            $chatId = $client->telegram_user_id;
            $onBot = filled($chatId) && $telegram->canReachClient($chatId);
            $created = $publish->invoice($client, $opened, $created, $deliver, true, $invoiceDate, $dueDate, deliver: ! $onBot);
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

        return [$created, $message];
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
            'kind' => Invoice::kindFor($request, (float) ($snapshot['amount_total'] ?? $request->amount_total ?? 0)),
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
            // Posting the invoice is the admin's go-ahead for an approval that was waiting on them.
            if ($request->awaitsAdminApproval()) {
                $approve->markApproved($request, 'admin', confirmOrder: true);
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
