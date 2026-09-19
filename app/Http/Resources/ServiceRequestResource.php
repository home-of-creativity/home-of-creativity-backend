<?php

namespace App\Http\Resources;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Services\ClickUpStatusMapper;
use App\Services\OdooClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceRequest */
class ServiceRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mapper = app(ClickUpStatusMapper::class);
        $odoo = app(OdooClient::class);
        $odooReady = $odoo->configured();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'number' => $this->number,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'work_type' => $this->work_type?->value,
            'execution_status' => $this->execution_status?->value,
            'execution_status_label' => $mapper->toClientLabel($this->execution_status),
            'odoo_quotation_id' => $this->odoo_quotation_id,
            'odoo_invoice_id' => $this->odoo_invoice_id,
            'odoo_quotation_url' => $odooReady && filled($this->odoo_quotation_id)
                ? $odoo->recordUrl('sale.order', (string) $this->odoo_quotation_id)
                : null,
            'odoo_invoice_url' => $odooReady && filled($this->odoo_invoice_id)
                ? $odoo->recordUrl('account.move', (string) $this->odoo_invoice_id)
                : null,
            'odoo_quotation_live' => is_array($this->odoo_quotation_live ?? null) ? $this->odoo_quotation_live : null,
            'odoo_invoice_live' => is_array($this->odoo_invoice_live ?? null) ? $this->odoo_invoice_live : null,
            'ai_analysis' => $this->ai_analysis,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_method' => $this->payment_method?->value,
            'gemini_status' => $this->gemini_status?->value,
            'gemini_attempts' => $this->gemini_attempts,
            'gemini_error' => $this->gemini_error,
            'gemini_processed_at' => $this->gemini_processed_at?->toIso8601String(),
            'quotation_amount' => $this->quotation_amount,
            'quotation_notes' => $this->quotation_notes,
            'pricing_package_id' => $this->pricing_package_id,
            'billing_period' => $this->billing_period,
            'payment_plan' => $this->payment_plan,
            'requires_full_payment' => (bool) $this->requires_full_payment,
            'allows_renewal' => (bool) $this->allows_renewal,
            'amount_total' => $this->amount_total,
            'amount_paid' => $this->amount_paid,
            'amount_remaining' => $this->amount_remaining,
            'paid_percent' => $this->paidPercent(),
            'remaining_percent' => $this->remainingPercent(),
            'expected_due' => $this->expectedDue(),
            'subscription_starts_at' => $this->subscription_starts_at?->toIso8601String(),
            'subscription_ends_at' => $this->subscription_ends_at?->toIso8601String(),
            'google_drive_folder_id' => $this->google_drive_folder_id,
            'google_drive_folder_url' => filled($this->google_drive_folder_id)
                ? 'https://drive.google.com/drive/folders/'.$this->google_drive_folder_id
                : null,
            'receipt_reupload_required' => (bool) $this->receipt_reupload_required,
            'receipt_reupload_reason' => $this->receipt_reupload_reason,
            'can_renew' => (bool) $this->allows_renewal && $this->status !== RequestStatus::Cancelled,
            'pricing_package' => $this->whenLoaded('pricingPackage'),
            'subscriptions' => $this->whenLoaded('subscriptions'),
            'client' => ClientResource::make($this->whenLoaded('client')),
            'briefs' => $this->whenLoaded('briefs'),
            'events' => $this->whenLoaded('events'),
            'files' => $this->whenLoaded('files'),
            'revisions' => $this->whenLoaded('revisions'),
            'quotations' => $this->whenLoaded('quotations'),
            'quotation_decisions' => $this->whenLoaded('quotationDecisions'),
            'invoices' => $this->whenLoaded('invoices'),
            'clickup_tasks' => $this->whenLoaded('clickupTasks'),
            'status_history' => $this->whenLoaded('statusHistory'),
            'integration_events' => $this->whenLoaded('integrationEvents'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
