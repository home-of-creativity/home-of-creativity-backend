<?php

namespace App\Actions;

use App\Enums\ClickUpSyncEvent;
use App\Enums\RequestStatus;
use App\Models\Employee;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Services\BrandedDocument;
use App\Services\OdooClient;
use App\Services\RequestStatusTransitionService;
use App\Services\TelegramNotifier;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SendQuotation
{
    public function __construct(
        private BrandedDocument $documents,
        private TelegramNotifier $telegram,
        private RequestStatusTransitionService $transitions,
    ) {}

    public function handle(ServiceRequest $request, float $amount, ?string $notes = null, string $actor = 'admin', ?Employee $employee = null): Quotation
    {
        if (! in_array($request->status, [RequestStatus::Submitted, RequestStatus::QuotationRejected], true)) {
            throw ValidationException::withMessages([
                'status' => 'Quotation can only be sent from submitted or quotation_rejected.',
            ]);
        }

        $quotation = DB::transaction(function () use ($request, $amount, $notes, $actor): Quotation {
            $version = ((int) $request->quotations()->max('version')) + 1;
            $pdfPath = $this->documents->quotationPdf($request, $amount, $notes, $version);

            $quotation = Quotation::query()->create([
                'request_id' => $request->id,
                'version' => $version,
                'amount' => $amount,
                'notes' => $notes,
                'pdf_path' => $pdfPath,
                'sent_at' => now(),
            ]);

            $request->forceFill([
                'quotation_amount' => $amount,
                'quotation_notes' => $notes,
            ])->save();

            $this->transitions->transition($request, RequestStatus::QuotationSent, $actor, "Quotation v{$version} sent.");

            $fileId = $this->telegram->sendStoredDocument(
                $request,
                $pdfPath,
                "عرض سعر #{$request->number} (v{$version})\nالمبلغ: {$amount}\n\nوافق أو ارفض من الأزرار أدناه.",
            );
            if ($fileId) {
                $quotation->forceFill(['telegram_file_id' => $fileId])->save();
            }

            $chatId = $request->client?->telegram_user_id;
            if ($chatId) {
                $ref = ResolveServiceRequest::displayNumber($request);
                $this->telegram->sendInlineActions(
                    (string) $chatId,
                    'اختر:',
                    [
                        ['text' => '✅ موافقة', 'callback_data' => "approve:{$ref}"],
                        ['text' => '❌ رفض', 'callback_data' => "reject:{$ref}"],
                    ],
                );
            }

            if (app(OdooClient::class)->configured()) {
                try {
                    $created = app(OdooClient::class)->createQuotation(
                        (string) ($request->client?->name ?? $request->number),
                        $request->client?->email,
                        $request->client?->phone,
                        $request->number,
                        $request->title,
                        $amount,
                    );
                    $request->forceFill(['odoo_quotation_id' => $created['odoo_quotation_id']])->save();
                    $request->client?->forceFill(['odoo_partner_id' => $created['odoo_partner_id']])->save();
                } catch (\Throwable) {
                    // Odoo failure must not block quotation send.
                }
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
}
