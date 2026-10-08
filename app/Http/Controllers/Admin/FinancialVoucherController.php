<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinancialVoucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinancialVoucherController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = FinancialVoucher::query()
            ->latest('id')
            ->limit(200)
            ->get([
                'id',
                'serial',
                'kind',
                'party_name',
                'amount',
                'currency',
                'issued_on',
                'signed_at',
                'updated_at',
            ]);

        return response()->json([
            'data' => $rows->map(fn (FinancialVoucher $voucher): array => $this->summary($voucher))->all(),
        ]);
    }

    public function show(FinancialVoucher $voucher): JsonResponse
    {
        return response()->json(['data' => $this->payload($voucher)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        $voucher = DB::transaction(function () use ($request, $validated): FinancialVoucher {
            $year = (int) now()->format('Y');
            $count = FinancialVoucher::query()->whereYear('created_at', $year)->lockForUpdate()->count() + 1;

            return FinancialVoucher::query()->create([
                ...$validated,
                'serial' => sprintf('HOC-V-%d-%04d', $year, $count),
                'user_id' => $request->user()?->id,
            ]);
        });

        return response()->json([
            'data' => $this->payload($voucher),
            'message' => 'تم حفظ المسند.',
        ], 201);
    }

    public function update(Request $request, FinancialVoucher $voucher): JsonResponse
    {
        $voucher->fill($this->validated($request, $voucher));
        $voucher->save();

        return response()->json([
            'data' => $this->payload($voucher->fresh()),
            'message' => 'تم حفظ المسند.',
        ]);
    }

    public function destroy(FinancialVoucher $voucher): JsonResponse
    {
        $voucher->delete();

        return response()->json(['data' => null, 'message' => 'تم حذف المسند.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?FinancialVoucher $existing = null): array
    {
        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(FinancialVoucher::KINDS)],
            'party_name' => ['required', 'string', 'max:160'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'currency' => ['required', 'string', Rule::in(FinancialVoucher::CURRENCIES)],
            'amount_words' => ['nullable', 'string', 'max:240'],
            'issued_on' => ['required', 'date'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:120'],
            'lines' => ['nullable', 'array', 'max:12'],
            'lines.*.memo' => ['nullable', 'string', 'max:180'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'signer_name' => ['nullable', 'string', 'max:120'],
            'counter_signer_name' => ['nullable', 'string', 'max:120'],
            'signature' => ['nullable', 'string', 'max:700000'],
            'counter_signature' => ['nullable', 'string', 'max:700000'],
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

        $kind = $validated['kind'];
        $amount = round((float) ($validated['amount'] ?? 0), 2);
        if ($kind === 'journal') {
            $debit = round(array_sum(array_column($lines, 'debit')), 2);
            $credit = round(array_sum(array_column($lines, 'credit')), 2);
            if ($lines === [] || abs($debit - $credit) > 0.009) {
                throw ValidationException::withMessages([
                    'lines' => 'سند القيد يحتاج بنوداً، ومجموع المدين يساوي مجموع الدائن.',
                ]);
            }
            $amount = $debit;
        } elseif (in_array($kind, ['receipt', 'payment'], true) && $amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'اكتب مبلغاً أكبر من صفر.',
            ]);
        }

        $signature = $this->signature($validated['signature'] ?? null, 'signature');
        $counter = $this->signature($validated['counter_signature'] ?? null, 'counter_signature');
        $signedAt = $existing?->signed_at;
        if ($signature === null) {
            $signedAt = null;
        } elseif ($signedAt === null) {
            $signedAt = now();
        }

        return [
            'kind' => $kind,
            'party_name' => trim($validated['party_name']),
            'amount' => $amount,
            'currency' => $validated['currency'],
            'amount_words' => $this->blank($validated['amount_words'] ?? null),
            'issued_on' => $validated['issued_on'],
            'purpose' => $this->blank($validated['purpose'] ?? null),
            'reference' => $this->blank($validated['reference'] ?? null),
            'lines' => $lines,
            'signer_name' => $this->blank($validated['signer_name'] ?? null),
            'counter_signer_name' => $this->blank($validated['counter_signer_name'] ?? null),
            'signature' => $signature,
            'counter_signature' => $counter,
            'signed_at' => $signedAt,
        ];
    }

    private function signature(?string $value, string $field): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (! preg_match('#^data:image/png;base64,[A-Za-z0-9+/=\r\n]+$#', $value)) {
            throw ValidationException::withMessages([
                $field => 'التوقيع يجب أن يكون صورة PNG.',
            ]);
        }

        return $value;
    }

    private function blank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(FinancialVoucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'serial' => $voucher->serial,
            'kind' => $voucher->kind,
            'party_name' => $voucher->party_name,
            'amount' => (float) $voucher->amount,
            'currency' => $voucher->currency,
            'issued_on' => $voucher->issued_on?->toDateString(),
            'signed' => $voucher->signed_at !== null,
            'updated_at' => $voucher->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(FinancialVoucher $voucher): array
    {
        return [
            ...$this->summary($voucher),
            'amount_words' => $voucher->amount_words,
            'purpose' => $voucher->purpose,
            'reference' => $voucher->reference,
            'lines' => $voucher->lines ?? [],
            'signer_name' => $voucher->signer_name,
            'counter_signer_name' => $voucher->counter_signer_name,
            'signature' => $voucher->signature,
            'counter_signature' => $voucher->counter_signature,
        ];
    }
}
