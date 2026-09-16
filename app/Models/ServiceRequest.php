<?php

namespace App\Models;

use App\Enums\ExecutionStatus;
use App\Enums\GeminiStatus;
use App\Enums\PaymentMethod;
use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Enums\WorkType;
use Database\Factories\ServiceRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ServiceRequest extends Model
{
    /** @use HasFactory<ServiceRequestFactory> */
    use HasFactory;

    protected $table = 'requests';

    protected $fillable = [
        'uuid',
        'number',
        'client_id',
        'pricing_package_id',
        'billing_period',
        'payment_plan',
        'title',
        'description',
        'status',
        'source',
        'work_type',
        'execution_status',
        'ai_analysis',
        'odoo_quotation_id',
        'odoo_invoice_id',
        'paid_at',
        'payment_method',
        'aggregate_version',
        'gemini_status',
        'gemini_attempts',
        'gemini_error',
        'gemini_processed_at',
        'quotation_amount',
        'quotation_notes',
        'amount_total',
        'amount_paid',
        'amount_remaining',
        'requires_full_payment',
        'allows_renewal',
        'subscription_starts_at',
        'subscription_ends_at',
        'google_drive_folder_id',
        'receipt_reupload_required',
        'receipt_reupload_reason',
        'odoo_won_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'source' => RequestSource::class,
            'work_type' => WorkType::class,
            'execution_status' => ExecutionStatus::class,
            'payment_method' => PaymentMethod::class,
            'gemini_status' => GeminiStatus::class,
            'ai_analysis' => 'array',
            'paid_at' => 'datetime',
            'gemini_processed_at' => 'datetime',
            'quotation_amount' => 'decimal:2',
            'amount_total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_remaining' => 'decimal:2',
            'requires_full_payment' => 'boolean',
            'allows_renewal' => 'boolean',
            'subscription_starts_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'receipt_reupload_required' => 'boolean',
            'odoo_won_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            if (! filled($request->uuid)) {
                $request->uuid = (string) Str::uuid();
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function pricingPackage(): BelongsTo
    {
        return $this->belongsTo(PricingPackage::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'request_id');
    }

    public function paymentReminders(): HasMany
    {
        return $this->hasMany(PaymentReminder::class, 'request_id');
    }

    public function driveDeliveries(): HasMany
    {
        return $this->hasMany(DriveDelivery::class, 'request_id');
    }

    public function hasRemainingBalance(): bool
    {
        return (float) ($this->amount_remaining ?? 0) > 0.009;
    }

    public function isFullyPaid(): bool
    {
        $total = (float) ($this->amount_total ?? $this->quotation_amount ?? 0);

        return $total > 0 && (float) ($this->amount_paid ?? 0) + 0.009 >= $total;
    }

    public function acceptsReceiptUpload(): bool
    {
        if ($this->receipt_reupload_required) {
            return true;
        }

        if ($this->status === RequestStatus::AwaitingPayment) {
            return true;
        }

        return $this->hasRemainingBalance();
    }

    public function files(): HasMany
    {
        return $this->hasMany(RequestFile::class, 'request_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RequestEvent::class, 'request_id');
    }

    public function briefs(): HasMany
    {
        return $this->hasMany(DepartmentBrief::class, 'request_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class, 'request_id');
    }

    public function clickupTasks(): HasMany
    {
        return $this->hasMany(ClickUpTask::class, 'request_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'request_id');
    }

    public function quotationDecisions(): HasMany
    {
        return $this->hasMany(QuotationDecision::class, 'request_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'request_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(RequestStatusHistory::class, 'request_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'request_id');
    }

    public function supportMessages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'request_id');
    }

    public function integrationEvents(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class, 'request_uuid', 'uuid');
    }
}
