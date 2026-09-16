<?php

namespace App\Actions;

use App\Enums\ClickUpSyncEvent;
use App\Enums\RequestStatus;
use App\Models\Employee;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\RequestStatusTransitionService;
use App\Services\TelegramNotifier;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SendQuotation
{
    public function __construct(
        private TelegramNotifier $telegram,
        private RequestStatusTransitionService $transitions,
        private OdooClient $odoo,
    ) {}

    /**
     * @param  list<array{title: string, amount: float|int|string, units?: float|int|string|null, notes?: string|null}>|null  $lines
     */
    public function handle(
        ServiceRequest $request,
        float $amount,
        ?string $notes = null,
        string $actor = 'admin',
        ?Employee $employee = null,
        ?array $lines = null,
        bool $skipStatusTransition = false,
    ): Quotation {
        if (! $skipStatusTransition && ! in_array($request->status, [RequestStatus::Submitted, RequestStatus::QuotationRejected], true)) {
            throw ValidationException::withMessages([
                'status' => 'Quotation can only be sent from submitted or quotation_rejected.',
            ]);
        }

        if ($lines !== null && $lines !== []) {
            $amount = (float) collect($lines)->sum(
                fn (array $line): float => (float) $line['amount'] * (float) ($line['units'] ?? 1),
            );
            $notes = $this->formatQuotationLines($lines);
        }

        $quotation = DB::transaction(function () use ($request, $amount, $notes, $actor, $lines, $skipStatusTransition): Quotation {
            $version = ((int) $request->quotations()->max('version')) + 1;
            $pdfPath = $this->resolveQuotationPdf($request, $amount, $notes, $version, $lines);

            $quotation = Quotation::query()->create([
                'request_id' => $request->id,
                'version' => $version,
                'amount' => $amount,
                'notes' => $notes,
                'pdf_path' => $pdfPath !== '' ? $pdfPath : null,
                'sent_at' => now(),
            ]);

            $request->forceFill([
                'quotation_amount' => $amount,
                'quotation_notes' => $notes,
            ])->save();

            if (! $skipStatusTransition) {
                $this->transitions->transition($request, RequestStatus::QuotationSent, $actor, "Quotation v{$version} sent.");
            }

            try {
                $caption = $this->quotationCaption($request, $amount, $notes, $version);
                if ($pdfPath !== '') {
                    $fileId = $this->telegram->sendStoredDocument(
                        $request,
                        $pdfPath,
                        $caption,
                    );
                    if ($fileId) {
                        $quotation->forceFill(['telegram_file_id' => $fileId])->save();
                    }
                } else {
                    $chatId = $request->client?->telegram_user_id;
                    if ($chatId) {
                        $this->telegram->send((string) $chatId, $caption);
                    }
                }

                $chatId = $request->client?->telegram_user_id;
                if ($chatId) {
                    $ref = ResolveServiceRequest::displayNumber($request);
                    $this->telegram->sendInlineKeyboard(
                        (string) $chatId,
                        'اختر:',
                        [
                            [['text' => '✅ موافقة', 'callback_data' => "approve:{$ref}"]],
                            [
                                ['text' => 'السعر غالي', 'callback_data' => "rjprice:{$ref}"],
                                ['text' => 'تأخير بالرد', 'callback_data' => "rjdelay:{$ref}"],
                            ],
                            [['text' => 'غير ذلك', 'callback_data' => "rjother:{$ref}"]],
                        ],
                    );
                }
            } catch (Throwable $exception) {
                if ((bool) config('services.telegram.strict')) {
                    throw $exception;
                }

                Log::warning('Telegram quotation delivery failed; quotation was still saved.', [
                    'request' => $request->number,
                    'error' => $exception->getMessage(),
                ]);
            }

            return $quotation->fresh() ?? $quotation;
        });

        app(SyncClickUpFromStaff::class)->handle(
            $request->fresh() ?? $request,
            ClickUpSyncEvent::Quotation,
            $employee,
            $notes,
        );

        return $quotation;
    }

    /**
     * @param  list<array{title: string, amount: float|int|string, notes?: string|null}>|null  $lines
     */
    private function resolveQuotationPdf(
        ServiceRequest $request,
        float $amount,
        ?string $notes,
        int $version,
        ?array $lines = null,
    ): string {
        if (! $this->odoo->configured()) {
            if ((bool) config('services.telegram.strict')) {
                throw ValidationException::withMessages([
                    'odoo' => 'Odoo integration is required to send quotation PDFs.',
                ]);
            }

            Log::warning('Odoo not configured; sending quotation without PDF.', [
                'request' => $request->number,
            ]);

            return '';
        }

        try {
            $created = $this->odoo->createQuotation(
                (string) ($request->client?->company_name ?: $request->client?->name ?? $request->number),
                $request->client?->email,
                $request->client?->phone,
                $request->number,
                $request->title,
                $amount,
                $notes,
                $lines,
            );
            $request->forceFill([
                'odoo_quotation_id' => $created['odoo_quotation_id'],
            ])->save();
            $request->client?->forceFill(['odoo_partner_id' => $created['odoo_partner_id']])->save();

            $pdfBinary = $this->odoo->downloadSaleOrderPdf($created['odoo_quotation_id']);
            if (! is_string($pdfBinary) || $pdfBinary === '') {
                throw ValidationException::withMessages([
                    'odoo' => 'Odoo returned an empty quotation PDF.',
                ]);
            }

            $relativePath = "quotations/{$request->number}-v{$version}-odoo.pdf";
            Storage::disk('local')->put($relativePath, $pdfBinary);

            return $relativePath;
        } catch (ValidationException $exception) {
            if ((bool) config('services.telegram.strict')) {
                throw $exception;
            }

            Log::warning('Odoo quotation PDF skipped; continuing locally.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);

            return '';
        } catch (Throwable $exception) {
            Log::error('Odoo quotation PDF failed.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);

            if ((bool) config('services.telegram.strict')) {
                throw ValidationException::withMessages([
                    'odoo' => 'Failed to create or download the Odoo quotation PDF.',
                ]);
            }

            return '';
        }
    }

    private function quotationCaption(ServiceRequest $request, float $amount, ?string $notes, int $version): string
    {
        $lines = [
            "عرض سعر #{$request->number} (v{$version})",
            "العنوان: {$request->title}",
            'المبلغ: '.number_format($amount, 2).' SYP',
        ];

        if (filled($notes)) {
            $lines[] = "تفاصيل العرض: {$notes}";
        }

        if (filled($request->description)) {
            $lines[] = "وصف الطلب: {$request->description}";
        }

        $lines[] = '';
        $lines[] = 'وافق أو ارفض من الأزرار أدناه.';

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{title: string, amount: float|int|string, units?: float|int|string|null, notes?: string|null}>  $lines
     */
    private function formatQuotationLines(array $lines): string
    {
        return collect($lines)
            ->values()
            ->map(function (array $line, int $index): string {
                $number = $index + 1;
                $units = (float) ($line['units'] ?? 1);
                $unitPrice = (float) $line['amount'];
                $row = "{$number}. {$line['title']} — {$units} × ".number_format($unitPrice, 2).' SYP = '.number_format($units * $unitPrice, 2).' SYP';
                if (filled($line['notes'] ?? null)) {
                    $row .= "\n   {$line['notes']}";
                }

                return $row;
            })
            ->implode("\n");
    }
}
