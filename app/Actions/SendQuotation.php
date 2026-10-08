<?php

namespace App\Actions;

use App\Enums\ClickUpSyncEvent;
use App\Enums\RequestStatus;
use App\Models\Employee;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\OdooLeadLog;
use App\Services\RequestStatusTransitionService;
use App\Services\TelegramNotifier;
use App\Support\CorrespondenceDocument;
use App\Support\Money;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SendQuotation
{
    public bool $deliveredToClient = false;

    public function __construct(
        private TelegramNotifier $telegram,
        private RequestStatusTransitionService $transitions,
        private OdooClient $odoo,
        private AlertTelegramDeliveryFailure $alertTelegramDeliveryFailure,
        private OdooLeadLog $leadLog,
        private CorrespondenceDocument $letters,
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
        ?bool $requiresFullPayment = null,
        ?array $prepared = null,
        ?string $channel = null,
    ): Quotation {
        $this->deliveredToClient = false;

        if (! $skipStatusTransition && ! in_array($request->status, [RequestStatus::Submitted, RequestStatus::QuotationRejected], true)) {
            throw ValidationException::withMessages([
                'status' => 'Quotation can only be sent from submitted or quotation_rejected.',
            ]);
        }

        if ($lines !== null && $lines !== []) {
            $amount = (float) collect($lines)->sum(function (array $line): float {
                $discount = min(100, max(0, (float) ($line['discount'] ?? 0)));

                return (float) $line['amount'] * (float) ($line['units'] ?? 1) * (1 - ($discount / 100));
            });
            $notes = $this->formatQuotationLines($lines);
        }

        $quotation = DB::transaction(function () use ($request, $amount, $notes, $actor, $lines, $skipStatusTransition, $requiresFullPayment, $prepared, $channel): Quotation {
            $version = ((int) $request->quotations()->max('version')) + 1;
            $pdfPath = $prepared !== null
                ? $this->adoptPreparedPdf($request, $prepared, $version)
                : $this->resolveQuotationPdf($request, $amount, $notes, $version, $lines);

            $quotation = Quotation::query()->create([
                'request_id' => $request->id,
                'version' => $version,
                'amount' => $amount,
                'notes' => $notes,
                'pdf_path' => $pdfPath !== '' ? $pdfPath : null,
                'sent_at' => now(),
            ]);

            $payload = [
                'quotation_amount' => $amount,
                'quotation_notes' => $notes,
            ];
            if ($requiresFullPayment !== null) {
                $payload['requires_full_payment'] = $requiresFullPayment;
                $payload['payment_plan'] = $requiresFullPayment ? 'full' : 'partial';
            }
            $request->forceFill($payload)->save();

            if (! $skipStatusTransition) {
                $this->transitions->transition($request, RequestStatus::QuotationSent, $actor, "Quotation v{$version} sent.");
            }
            $this->leadLog->quotationSent($request, $amount, $version);

            try {
                $caption = $this->quotationCaption($request, $amount, $notes, $version);
                if ($channel === 'email' || $channel === 'phone') {
                    $absolute = $pdfPath !== '' ? Storage::disk('local')->path($pdfPath) : null;
                    $this->deliveredToClient = $request->client !== null && app(DeliverClientDocument::class)->send(
                        $request->client,
                        $channel,
                        $caption,
                        $absolute,
                        $request->number.'.pdf',
                    );

                    return $quotation->fresh() ?? $quotation;
                }
                $chatId = $request->client?->telegram_user_id;
                $keyboard = $chatId ? $this->quotationKeyboard($request) : null;
                if ($pdfPath !== '') {
                    $fileId = $this->telegram->sendStoredDocument(
                        $request,
                        $pdfPath,
                        $caption,
                        $keyboard,
                    );
                    if ($fileId) {
                        $quotation->forceFill(['telegram_file_id' => $fileId])->save();
                        $this->deliveredToClient = true;
                    }
                } elseif ($chatId && $this->telegram->canReachClient($chatId)) {
                    $this->telegram->sendInlineKeyboard(
                        (string) $chatId,
                        $caption,
                        $keyboard['inline_keyboard'] ?? [],
                    );
                    $this->deliveredToClient = true;
                }
            } catch (Throwable $exception) {
                if ((bool) config('services.telegram.strict')) {
                    throw $exception;
                }

                Log::warning('Telegram quotation delivery failed; quotation was still saved.', [
                    'request' => $request->number,
                    'error' => $exception->getMessage(),
                ]);
                if ($this->telegram->canReachClient($chatId)) {
                    $this->alertTelegramDeliveryFailure->handle($request, 'quotation', $exception->getMessage());
                }
            }

            return $quotation->fresh() ?? $quotation;
        });

        $fresh = $request->fresh(['client']) ?? $request;
        if ($fresh->client) {
            app(SyncClientExpectedRevenue::class)->handle($fresh->client);
        }

        app(SyncClickUpFromStaff::class)->handle(
            $fresh,
            ClickUpSyncEvent::Quotation,
            $employee,
            $notes,
        );

        return $quotation;
    }

    /**
     * @param  array<string, mixed>  $prepared
     */
    private function adoptPreparedPdf(ServiceRequest $request, array $prepared, int $version): string
    {
        if (filled($prepared['odoo_quotation_id'] ?? null)) {
            $request->forceFill(['odoo_quotation_id' => (string) $prepared['odoo_quotation_id']])->save();
        }
        if (filled($prepared['odoo_partner_id'] ?? null)) {
            $request->client?->forceFill(['odoo_partner_id' => (string) $prepared['odoo_partner_id']])->save();
        }

        $previewPath = (string) ($prepared['pdf_path'] ?? '');
        if ($previewPath === '' || ! Storage::disk('local')->exists($previewPath)) {
            return '';
        }

        $suffix = filled($prepared['odoo_quotation_id'] ?? null) ? 'odoo' : 'local';
        $finalPath = "quotations/{$request->number}-v{$version}-{$suffix}.pdf";
        Storage::disk('local')->move($previewPath, $finalPath);

        return $finalPath;
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
            return $this->localQuotationPdf($request, $amount, $notes, $version, $lines);
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
                $request->client?->odoo_partner_id,
                $request->client?->odoo_lead_id,
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
        } catch (Throwable $exception) {
            Log::warning('Odoo quotation PDF skipped; using the correspondence template.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);

            return $this->localQuotationPdf($request, $amount, $notes, $version, $lines);
        }
    }

    /**
     * @param  list<array{title: string, amount: float|int|string, notes?: string|null}>|null  $lines
     */
    private function localQuotationPdf(
        ServiceRequest $request,
        float $amount,
        ?string $notes,
        int $version,
        ?array $lines,
    ): string {
        try {
            return $this->letters->quotation(
                $request,
                $amount,
                $notes,
                $lines,
                "quotations/{$request->number}-v{$version}-local.pdf",
            );
        } catch (Throwable $exception) {
            Log::error('Correspondence quotation PDF failed.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);

            if ((bool) config('services.telegram.strict')) {
                throw ValidationException::withMessages([
                    'pdf' => 'تعذر إنشاء ملف عرض السعر.',
                ]);
            }

            return '';
        }
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function quotationKeyboard(ServiceRequest $request): array
    {
        $ref = ResolveServiceRequest::displayNumber($request);

        return [
            'inline_keyboard' => [
                [['text' => '✅ موافقة', 'callback_data' => "approve:{$ref}"]],
                [['text' => '❌ رفض', 'callback_data' => "reject:{$ref}"]],
            ],
        ];
    }

    private function quotationCaption(ServiceRequest $request, float $amount, ?string $notes, int $version): string
    {
        $lines = [
            "عرض سعر #{$request->number} (v{$version})",
            "العنوان: {$request->title}",
            'المبلغ: '.Money::format($amount),
        ];

        if (filled($notes)) {
            $lines[] = $notes;
        } elseif (filled($request->description)) {
            $lines[] = $request->description;
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
                $row = "{$number}. {$line['title']} — {$units} × ".Money::format($unitPrice).' = '.Money::format($units * $unitPrice);
                if (filled($line['notes'] ?? null)) {
                    $row .= "\n   {$line['notes']}";
                }

                return $row;
            })
            ->implode("\n");
    }
}
