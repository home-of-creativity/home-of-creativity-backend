<?php

namespace App\Http\Controllers\Admin;

use App\Actions\DeliverClientDocument;
use App\Enums\StaffAbility;
use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Support\ResolveOdooClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * A quotation or invoice as Odoo shows it: the printed PDF for preview and print, its
 * chatter (messages, internal notes, tracked changes), and internal notes added from
 * the dashboard.
 */
class OdooPaperController extends Controller
{
    private const MODELS = ['quotations' => 'sale.order', 'invoices' => 'account.move'];

    public function pdf(Request $request, string $kind, int $id, OdooClient $odoo): Response
    {
        $model = $this->model($kind, $odoo);
        $snapshot = $this->snapshot($odoo, $model, $id);
        $proforma = $model === 'sale.order' && $request->boolean('proforma');
        abort_if($proforma && ! $request->user()?->canAbility(StaffAbility::OpsClients->value.'.proforma'), 403);
        $binary = match (true) {
            $proforma => $odoo->downloadProformaPdf($id),
            $model === 'sale.order' => $odoo->downloadSaleOrderPdf($id),
            default => $odoo->downloadInvoicePdf($id),
        };
        abort_unless(is_string($binary) && $binary !== '', 502, 'تعذر تجهيز الملف من أودو.');

        $name = preg_replace('/[^\w.\-]+/u', '-', ($proforma ? 'proforma-' : '').(string) ($snapshot['name'] ?? $id)) ?: (string) $id;

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function activity(string $kind, int $id, OdooClient $odoo): JsonResponse
    {
        $model = $this->model($kind, $odoo);
        $this->snapshot($odoo, $model, $id);
        try {
            $messages = $odoo->paperMessages($model, $id);
        } catch (\Throwable $exception) {
            Log::warning('Odoo chatter read failed.', ['model' => $model, 'id' => $id, 'error' => $exception->getMessage()]);
            abort(502, 'تعذر قراءة سجل أودو.');
        }

        return response()->json(['data' => $messages, 'message' => 'ok']);
    }

    public function note(Request $request, string $kind, int $id, OdooClient $odoo): JsonResponse
    {
        $model = $this->model($kind, $odoo);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $this->snapshot($odoo, $model, $id);

        $lines = preg_split('/\R/u', trim($data['body'])) ?: [];
        $text = implode('<br/>', array_map(fn (string $line): string => e($line), $lines));
        $author = e((string) ($request->user()?->name ?: 'HOC'));
        $html = "<p>{$text}</p><p><small>{$author} من الداشبورد</small></p>";

        try {
            $odoo->postNote($model, $id, $html);
        } catch (\Throwable $exception) {
            Log::warning('Odoo note failed.', ['model' => $model, 'id' => $id, 'error' => $exception->getMessage()]);
            abort(502, 'تعذر تسجيل الملاحظة في أودو.');
        }

        return response()->json(['data' => $odoo->paperMessages($model, $id), 'message' => 'سُجّلت الملاحظة في أودو.'], 201);
    }

    /**
     * The order's pro-forma invoice to the client by email or WhatsApp. It is not an
     * invoice: nothing is posted in Odoo and the request does not move to payment.
     */
    public function sendProforma(Request $request, int $id, OdooClient $odoo, ResolveOdooClient $resolve, DeliverClientDocument $deliver): JsonResponse
    {
        $this->model('quotations', $odoo);
        $data = $request->validate(['deliver' => ['required', 'in:email,whatsapp,both']]);
        $snapshot = $this->snapshot($odoo, 'sale.order', $id);
        abort_if(($snapshot['state'] ?? '') === 'cancel', 422, 'عرض السعر ملغى في أودو.');

        $client = $resolve->forPartner($snapshot['partner_id'] ?? null)
            ?? ServiceRequest::query()->where('odoo_quotation_id', (string) $id)->latest('id')->first()?->client;
        abort_if($client === null, 422, 'عميل عرض السعر غير مربوط بعميل في الداشبورد. اربط شريك أودو بالعميل أولاً.');
        $channels = $data['deliver'] === 'both' ? ['email', 'whatsapp'] : [$data['deliver']];
        abort_if(in_array('email', $channels, true) && ! filled($client->email), 422, 'العميل بلا بريد إلكتروني.');
        abort_if(in_array('whatsapp', $channels, true) && ! filled($client->phone), 422, 'العميل بلا رقم واتساب.');

        $binary = $odoo->downloadProformaPdf($id);
        abort_unless(is_string($binary) && $binary !== '', 502, 'تعذر تجهيز الفاتورة الشكلية من أودو.');
        $relative = "quotations/proforma-{$id}.pdf";
        Storage::disk('local')->put($relative, $binary);

        $name = (string) ($snapshot['name'] ?? $id);
        $sent = $deliver->send(
            $client,
            $data['deliver'],
            "الفاتورة الشكلية لعرض السعر {$name} من Home of Creativity. هي للاطلاع والدفع المسبق، والفاتورة النهائية تصلك بعد إصدارها.",
            Storage::disk('local')->path($relative),
            "proforma-{$name}.pdf",
        );
        abort_unless($sent, 502, 'تعذر إرسال الفاتورة الشكلية إلى البريد أو واتساب.');

        $via = ['email' => 'البريد', 'whatsapp' => 'واتساب', 'both' => 'البريد وواتساب'][$data['deliver']];
        $author = e((string) ($request->user()?->name ?: 'HOC'));
        try {
            $odoo->postNote('sale.order', $id, "<p>أُرسلت الفاتورة الشكلية للعميل عبر {$via}.</p><p><small>{$author} من الداشبورد</small></p>");
        } catch (\Throwable $exception) {
            Log::warning('Odoo pro-forma note failed.', ['order' => $id, 'error' => $exception->getMessage()]);
        }

        return response()->json(['data' => ['id' => $id, 'name' => $name], 'message' => "أُرسلت الفاتورة الشكلية {$name} للعميل."]);
    }

    private function model(string $kind, OdooClient $odoo): string
    {
        abort_unless($odoo->configured(), 422, 'Odoo is not configured.');

        return self::MODELS[$kind] ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function snapshot(OdooClient $odoo, string $model, int $id): array
    {
        try {
            $snapshot = $model === 'sale.order' ? $odoo->quotationSnapshot($id) : $odoo->invoiceSnapshot($id);
        } catch (\Throwable $exception) {
            abort(502, 'تعذر الوصول إلى أودو: '.$exception->getMessage());
        }

        return $snapshot ?? abort(404, $model === 'sale.order' ? 'عرض السعر غير موجود في أودو.' : 'الفاتورة غير موجودة في أودو.');
    }
}
