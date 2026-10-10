<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AdminBotDesk;
use App\Http\Controllers\Controller;
use App\Models\OpsExpense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    public function index(Request $request, AdminBotDesk $desk): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'invoice_state' => ['nullable', Rule::in(['paid', 'partial', 'open', 'overdue', 'draft', 'cancelled'])],
            'client' => ['nullable', 'string', 'max:160'],
            'currency' => ['nullable', 'string', 'max:12'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'amount_min' => ['nullable', 'numeric', 'min:0'],
            'amount_max' => ['nullable', 'numeric', 'min:0'],
            'category' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', Rule::in(['date', 'amount', 'residual', 'due', 'client'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json([
            'data' => $desk->finance($filters),
            'message' => 'ok',
        ]);
    }

    public function sync(AdminBotDesk $desk): JsonResponse
    {
        $result = $desk->syncOdooInvoices();

        return response()->json([
            'data' => $result,
            'message' => $result['synced'] ? 'تمت المزامنة مع أودو.' : 'تعذّرت المزامنة مع أودو الآن.',
        ]);
    }

    public function storeExpense(Request $request, AdminBotDesk $desk): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'category' => ['required', 'string', Rule::in(OpsExpense::categories())],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $saved = $desk->recordExpense(
            (float) $validated['amount'],
            $validated['category'],
            filled($validated['note'] ?? null) ? trim((string) $validated['note']) : null,
            null,
        );

        return response()->json(['data' => $saved['finance'], 'message' => 'تم تسجيل المصروف.'], 201);
    }
}
