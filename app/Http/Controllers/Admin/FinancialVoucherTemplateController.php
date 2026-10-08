<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinancialVoucher;
use App\Models\FinancialVoucherTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinancialVoucherTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = FinancialVoucherTemplate::query()->orderBy('name')->get(['id', 'name', 'kind']);

        return response()->json([
            'data' => $rows->map(fn (FinancialVoucherTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'kind' => $template->kind,
            ])->all(),
        ]);
    }

    public function show(FinancialVoucherTemplate $template): JsonResponse
    {
        return response()->json(['data' => $this->payload($template)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string', Rule::in(FinancialVoucher::KINDS)],
            'party_name' => ['nullable', 'string', 'max:160'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'currency' => ['required', 'string', Rule::in(FinancialVoucher::CURRENCIES)],
            'amount_words' => ['nullable', 'string', 'max:240'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:120'],
            'lines' => ['nullable', 'array', 'max:12'],
            'lines.*.memo' => ['nullable', 'string', 'max:180'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'signer_name' => ['nullable', 'string', 'max:120'],
            'counter_signer_name' => ['nullable', 'string', 'max:120'],
            'background' => ['nullable', 'string', 'max:900000'],
        ]);

        $lines = collect($validated['lines'] ?? [])
            ->map(fn (array $line): array => [
                'memo' => trim((string) ($line['memo'] ?? '')),
                'debit' => round((float) ($line['debit'] ?? 0), 2),
                'credit' => round((float) ($line['credit'] ?? 0), 2),
            ])
            ->filter(fn (array $line): bool => $line['memo'] !== '' || $line['debit'] > 0 || $line['credit'] > 0)
            ->values()
            ->all();

        $amount = round((float) ($validated['amount'] ?? 0), 2);
        if ($validated['kind'] === 'delivery') {
            $sum = round(array_sum(array_column($lines, 'debit')), 2);
            if ($sum > 0) {
                $amount = $sum;
            }
        }

        $data = [
            'party_name' => trim((string) ($validated['party_name'] ?? '')),
            'amount' => $amount,
            'currency' => $validated['currency'],
            'amount_words' => $this->blank($validated['amount_words'] ?? null),
            'purpose' => $this->blank($validated['purpose'] ?? null),
            'reference' => $this->blank($validated['reference'] ?? null),
            'lines' => $lines,
            'signer_name' => $this->blank($validated['signer_name'] ?? null),
            'counter_signer_name' => $this->blank($validated['counter_signer_name'] ?? null),
            'background' => $this->image($validated['background'] ?? null),
        ];

        $template = FinancialVoucherTemplate::query()->updateOrCreate(
            ['name' => trim($validated['name'])],
            [
                'kind' => $validated['kind'],
                'data' => $data,
                'user_id' => $request->user()?->id,
            ],
        );

        return response()->json([
            'data' => $this->payload($template),
            'message' => 'حُفظ القالب مع البيانات.',
        ], $template->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(FinancialVoucherTemplate $template): JsonResponse
    {
        $template->delete();

        return response()->json(['data' => null, 'message' => 'تم حذف القالب.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(FinancialVoucherTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'kind' => $template->kind,
            'data' => $template->data ?? [],
        ];
    }

    private function image(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (! preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=\r\n]+$#', $value)) {
            throw ValidationException::withMessages([
                'background' => 'الخلفية يجب أن تكون صورة.',
            ]);
        }

        return $value;
    }

    private function blank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
