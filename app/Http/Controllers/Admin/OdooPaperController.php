<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\OdooClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * A quotation or invoice as Odoo shows it: the printed PDF for preview and print, its
 * chatter (messages, internal notes, tracked changes), and internal notes added from
 * the dashboard.
 */
class OdooPaperController extends Controller
{
    private const MODELS = ['quotations' => 'sale.order', 'invoices' => 'account.move'];

    public function pdf(string $kind, int $id, OdooClient $odoo): Response
    {
        $model = $this->model($kind, $odoo);
        $snapshot = $this->snapshot($odoo, $model, $id);
        $binary = $model === 'sale.order' ? $odoo->downloadSaleOrderPdf($id) : $odoo->downloadInvoicePdf($id);
        abort_unless(is_string($binary) && $binary !== '', 502, 'تعذر تجهيز الملف من أودو.');

        $name = preg_replace('/[^\w.\-]+/u', '-', (string) ($snapshot['name'] ?? $id)) ?: (string) $id;

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
