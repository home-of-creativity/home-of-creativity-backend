<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinancialVoucherSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FinancialVoucherSignatureController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = FinancialVoucherSignature::query()
            ->orderByDesc('id')
            ->get(['id', 'name', 'image', 'created_at']);

        return response()->json([
            'data' => $rows->map(fn (FinancialVoucherSignature $row): array => $this->payload($row))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'image' => ['required', 'string', 'max:900000'],
        ]);

        $row = FinancialVoucherSignature::query()->create([
            'name' => trim($validated['name']),
            'image' => $this->image($validated['image']),
            'user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'data' => $this->payload($row),
            'message' => 'حُفظ التوقيع في القائمة.',
        ], 201);
    }

    public function destroy(FinancialVoucherSignature $signature): JsonResponse
    {
        $signature->delete();

        return response()->json(['data' => null, 'message' => 'تم حذف التوقيع.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(FinancialVoucherSignature $row): array
    {
        return [
            'id' => $row->id,
            'name' => $row->name,
            'image' => $row->image,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    private function image(string $value): string
    {
        $value = trim($value);
        if (! preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=\r\n]+$#', $value)) {
            throw ValidationException::withMessages([
                'image' => 'التوقيع يجب أن يكون صورة.',
            ]);
        }

        return $value;
    }
}
