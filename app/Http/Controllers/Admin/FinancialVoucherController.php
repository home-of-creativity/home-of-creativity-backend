<?php

namespace App\Http\Controllers\Admin;

use App\Actions\DeliverClientDocument;
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
                'title',
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

    public function nextSerial(): JsonResponse
    {
        return response()->json([
            'data' => ['serial' => FinancialVoucher::nextSerial()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        $voucher = DB::transaction(function () use ($request, $validated): FinancialVoucher {
            $serial = $validated['serial'] ?? null;
            unset($validated['serial']);
            if ($serial === null) {
                // Lock this year's rows so two saves at once do not share one number.
                FinancialVoucher::query()
                    ->where('serial', 'like', sprintf('HOC-V-%d-%%', (int) now()->format('Y')))
                    ->lockForUpdate()
                    ->orderByDesc('serial')
                    ->value('serial');
                $serial = FinancialVoucher::nextSerial();
            }

            return FinancialVoucher::query()->create([
                ...$validated,
                'serial' => $serial,
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
        $validated = $this->validated($request, $voucher);
        $voucher->fill($validated);
        $voucher->save();

        return response()->json([
            'data' => $this->payload($voucher->fresh()),
            'message' => 'تم حفظ المسند.',
        ]);
    }

    /**
     * Sends the voucher as the PDF the dashboard rendered, by email, WhatsApp or both, to
     * anyone (not only a client).
     */
    public function send(Request $request, FinancialVoucher $voucher, DeliverClientDocument $deliver): JsonResponse
    {
        $validated = $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:15360'],
            'channel' => ['required', Rule::in(['email', 'whatsapp', 'both'])],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'required_if:channel,email,both', 'email', 'max:190'],
            'phone' => ['nullable', 'required_if:channel,whatsapp,both', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $title = $voucher->title ?: $this->kindName($voucher->kind);
        $text = trim(implode("\n\n", array_filter([
            "مرحباً {$validated['name']}،",
            $validated['message'] ?? null,
            "مرفق {$title} رقم {$voucher->serial} بتاريخ ".$voucher->issued_on?->format('Y/m/d').'.',
            'Home of Creativity — بيت الإبداع',
        ])));
        $path = $request->file('pdf')->getRealPath();
        $filename = "{$voucher->serial}.pdf";

        $sent = [];
        foreach ($validated['channel'] === 'both' ? ['email', 'whatsapp'] : [$validated['channel']] as $channel) {
            $sent[$channel] = $deliver->deliver(
                $channel === 'email' ? $validated['email'] : null,
                $channel === 'whatsapp' ? $validated['phone'] : null,
                $channel,
                $text,
                $path,
                $filename,
                "voucher {$voucher->serial}",
            );
        }

        if (! in_array(true, $sent, true)) {
            return response()->json(['data' => ['sent' => $sent], 'message' => 'تعذر إرسال المستند. تأكد من البريد أو رقم واتساب.'], 422);
        }

        $to = array_filter([$validated['name'], $sent['email'] ?? false ? $validated['email'] : null, $sent['whatsapp'] ?? false ? $validated['phone'] : null]);
        $voucher->forceFill(['sent_at' => now(), 'sent_to' => mb_substr(implode(' · ', $to), 0, 255)])->save();
        $failed = array_keys(array_filter($sent, fn (bool $ok): bool => ! $ok));

        return response()->json([
            'data' => ['sent' => $sent, 'voucher' => $this->payload($voucher->fresh())],
            'message' => $failed === []
                ? 'أُرسل المستند.'
                : 'أُرسل المستند، وتعذر الإرسال عبر '.($failed[0] === 'email' ? 'البريد' : 'واتساب').'.',
        ]);
    }

    private function kindName(string $kind): string
    {
        return match ($kind) {
            'delivery' => 'سند التسليم',
            'receipt' => 'سند القبض',
            'payment' => 'سند الصرف',
            'journal' => 'سند القيد',
            'settlement' => 'المخالصة المالية',
            'notes' => 'محضر الملاحظات',
            default => 'المستند',
        };
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
            'title' => ['nullable', 'string', 'max:120'],
            'party_name' => ['required', 'string', 'max:160'],
            'serial' => ['nullable', 'string', 'max:24'],
            'amount' => ['nullable', 'numeric', 'min:-100000000', 'max:100000000'],
            'remaining' => ['nullable', 'numeric', 'min:-100000000', 'max:100000000'],
            'show_remaining' => ['sometimes', 'boolean'],
            'currency' => ['required', 'string', Rule::in(FinancialVoucher::CURRENCIES)],
            'amount_words' => ['nullable', 'string', 'max:240'],
            'issued_on' => ['required', 'date'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:120'],
            'lines' => ['nullable', 'array', 'max:'.FinancialVoucher::lineLimits($request->input('kind'))[0]],
            'lines.*.memo' => ['nullable', 'string', 'max:'.FinancialVoucher::lineLimits($request->input('kind'))[1]],
            'lines.*.debit' => ['nullable', 'numeric', 'min:-100000000', 'max:100000000'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:-100000000', 'max:100000000'],
            'signer_name' => ['nullable', 'string', 'max:120'],
            'signer_title' => ['nullable', 'string', 'max:60'],
            'counter_signer_name' => ['nullable', 'string', 'max:120'],
            'counter_signer_title' => ['nullable', 'string', 'max:60'],
            'signature' => ['nullable', 'string', 'max:700000'],
            'counter_signature' => ['nullable', 'string', 'max:700000'],
            'background' => ['nullable', 'string', 'max:900000'],
        ]);

        $lines = collect($validated['lines'] ?? [])
            ->map(fn (array $line): array => [
                'memo' => trim((string) ($line['memo'] ?? '')),
                'debit' => round((float) ($line['debit'] ?? 0), 2),
                'credit' => round((float) ($line['credit'] ?? 0), 2),
            ])
            ->filter(fn (array $line): bool => $line['memo'] !== '' || $line['debit'] != 0 || $line['credit'] != 0)
            ->values()
            ->all();

        $kind = $validated['kind'];
        $amount = round((float) ($validated['amount'] ?? 0), 2);
        if ($kind === 'notes') {
            // Minutes of a conversation: numbered notes, no money.
            if ($lines === []) {
                throw ValidationException::withMessages([
                    'lines' => 'اكتب ملاحظة واحدة على الأقل.',
                ]);
            }
            $amount = 0.0;
        } elseif ($kind === 'journal') {
            $debit = round(array_sum(array_column($lines, 'debit')), 2);
            $credit = round(array_sum(array_column($lines, 'credit')), 2);
            if ($lines === [] || abs($debit - $credit) > 0.009) {
                throw ValidationException::withMessages([
                    'lines' => 'سند القيد يحتاج بنوداً، ومجموع المدين يساوي مجموع الدائن.',
                ]);
            }
            $amount = $debit;
        } elseif ($kind === 'delivery') {
            // A negative line is a deduction the other party sees as such.
            $priced = array_filter($lines, fn (array $line): bool => $line['debit'] != 0);
            if ($priced !== []) {
                $amount = round(array_sum(array_column($priced, 'debit')), 2);
            }
            if ($amount == 0) {
                throw ValidationException::withMessages([
                    'amount' => 'اكتب مبلغاً غير الصفر.',
                ]);
            }
        } elseif (in_array($kind, ['receipt', 'payment'], true) && $amount == 0) {
            throw ValidationException::withMessages([
                'amount' => 'اكتب مبلغاً غير الصفر.',
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

        $data = [
            'kind' => $kind,
            'title' => $this->blank($validated['title'] ?? null),
            'party_name' => trim($validated['party_name']),
            'amount' => $amount,
            'remaining' => array_key_exists('remaining', $validated) && $validated['remaining'] !== null
                ? round((float) $validated['remaining'], 2)
                : null,
            'show_remaining' => (bool) ($validated['show_remaining'] ?? false),
            'currency' => $validated['currency'],
            'amount_words' => $this->blank($validated['amount_words'] ?? null),
            'issued_on' => $validated['issued_on'],
            'purpose' => $this->blank($validated['purpose'] ?? null),
            'reference' => $this->blank($validated['reference'] ?? null),
            'lines' => $lines,
            'signer_name' => $this->blank($validated['signer_name'] ?? null),
            'signer_title' => $this->blank($validated['signer_title'] ?? null),
            'counter_signer_name' => $this->blank($validated['counter_signer_name'] ?? null),
            'counter_signer_title' => $this->blank($validated['counter_signer_title'] ?? null),
            'signature' => $signature,
            'counter_signature' => $counter,
            'background' => $this->image($validated['background'] ?? null),
            'signed_at' => $signedAt,
        ];

        $rawSerial = trim((string) ($validated['serial'] ?? ''));
        if ($rawSerial !== '') {
            $serial = FinancialVoucher::normalizeSerial($rawSerial);
            if ($serial === '' || preg_match('/^HOC-V-\d{4}-\d{4}$/', $serial) !== 1) {
                throw ValidationException::withMessages([
                    'serial' => 'رقم المستند غير صالح. مثال: 7 أو N0007.',
                ]);
            }
            $taken = FinancialVoucher::query()
                ->where('serial', $serial)
                ->when($existing !== null, fn ($query) => $query->where('id', '!=', $existing->id))
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages([
                    'serial' => 'رقم المستند مستخدم مسبقاً.',
                ]);
            }
            $data['serial'] = $serial;
        }

        return $data;
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

    /**
     * @return array<string, mixed>
     */
    private function summary(FinancialVoucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'serial' => $voucher->serial,
            'kind' => $voucher->kind,
            'title' => $voucher->title,
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
            'remaining' => $voucher->remaining !== null ? (float) $voucher->remaining : null,
            'show_remaining' => (bool) $voucher->show_remaining,
            'purpose' => $voucher->purpose,
            'reference' => $voucher->reference,
            'lines' => $voucher->lines ?? [],
            'signer_name' => $voucher->signer_name,
            'signer_title' => $voucher->signer_title,
            'counter_signer_name' => $voucher->counter_signer_name,
            'counter_signer_title' => $voucher->counter_signer_title,
            'signature' => $voucher->signature,
            'counter_signature' => $voucher->counter_signature,
            'background' => $voucher->background,
            'sent_at' => $voucher->sent_at?->toIso8601String(),
            'sent_to' => $voucher->sent_to,
        ];
    }
}
