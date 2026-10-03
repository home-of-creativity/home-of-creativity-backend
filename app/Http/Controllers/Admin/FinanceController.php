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
    public function index(AdminBotDesk $desk): JsonResponse
    {
        return response()->json(['data' => $desk->finance(), 'message' => 'ok']);
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
