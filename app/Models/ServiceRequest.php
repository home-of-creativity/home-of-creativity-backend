<?php

namespace App\Models;

use App\Enums\ExecutionStatus;
use App\Enums\GeminiStatus;
use App\Enums\PaymentMethod;
use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Enums\WorkType;
use App\Support\BillingPeriod;
use App\Support\ResolveServiceRequest;
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
        'work_plan',
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
        'drive_last_activity_at',
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
            'work_plan' => 'array',
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
            'drive_last_activity_at' => 'datetime',
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
        return $this->belongsTo(Client::class)->withTrashed();
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

    public function googleDriveFolderUrl(): ?string
    {
        $id = $this->google_drive_folder_id ?: config('services.google.drive_parent_folder_id');

        return filled($id) ? 'https://drive.google.com/drive/folders/'.$id : null;
    }

    /**
     * @return list<string>
     */
    public function driveFolderSegments(): array
    {
        $this->loadMissing(['client', 'pricingPackage']);

        return [
            $this->client?->driveCompanyFolderName() ?? 'شركة',
            $this->drivePackageFolderName(),
            $this->driveRequestFolderName(),
        ];
    }

    public function drivePackageFolderName(): string
    {
        $this->loadMissing('pricingPackage');
        $package = $this->pricingPackage;
        if ($package === null) {
            return 'طلب يدوي';
        }

        $name = trim((string) ($package->name_ar ?: $package->name_en ?: $package->slug));
        if ($name === '') {
            $name = 'باقة';
        }

        $period = trim((string) ($this->billing_period ?? ''));
        if ($period === '') {
            return $name;
        }

        return $name.' — '.BillingPeriod::labelAr($period);
    }

    public function driveRequestFolderName(): string
    {
        $stamp = $this->paid_at ?? $this->created_at ?? now();
        $folderDate = $stamp->timezone((string) config('app.timezone'))->format('Y-m-d');
        $ref = ResolveServiceRequest::displayNumber($this);
        $title = trim((string) $this->title);

        return trim('#'.$ref.($title !== '' ? ' '.$title : '').' '.$folderDate);
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

    public function needsPaymentCollection(): bool
    {
        if (in_array($this->status, [RequestStatus::Completed, RequestStatus::Cancelled], true)) {
            return false;
        }

        return ! $this->isFullyPaid();
    }

    public function paidPercent(): float
    {
        $total = (float) ($this->amount_total ?? $this->quotation_amount ?? 0);
        if ($total <= 0) {
            return 0.0;
        }

        $paid = min((float) ($this->amount_paid ?? 0), $total);

        return round($paid / $total * 100, 1);
    }

    public function remainingPercent(): float
    {
        return round(max(100 - $this->paidPercent(), 0), 1);
    }

    public function expectedDue(): float
    {
        $total = (float) ($this->amount_total ?? $this->quotation_amount ?? 0);
        $paid = (float) ($this->amount_paid ?? 0);
        $remaining = $total > 0 ? max(round($total - $paid, 2), 0) : 0;

        if ($paid > 0.009 || $this->paid_at) {
            return $remaining;
        }

        if ($this->requires_full_payment || $this->payment_plan === 'full') {
            return round($total, 2);
        }

        return round($total * 0.5, 2);
    }

    public function acceptsReceiptUpload(): bool
    {
        if ($this->receipt_reupload_required) {
            return true;
        }

        if ($this->status === RequestStatus::AwaitingPayment) {
            return true;
        }

        return $this->hasRemainingBalance()
            && in_array($this->status, [
                RequestStatus::PaymentConfirmed,
                RequestStatus::InProgress,
                RequestStatus::ReadyForReview,
                RequestStatus::RevisionRequested,
            ], true);
    }

    public function canRenew(): bool
    {
        return (bool) $this->allows_renewal && $this->status === RequestStatus::Completed;
    }

    public function isLiveForDrivePoll(): bool
    {
        return in_array($this->status, [
            RequestStatus::PaymentConfirmed,
            RequestStatus::InProgress,
            RequestStatus::RevisionRequested,
            RequestStatus::ReadyForReview,
        ], true);
    }

    public function allowsClientRevision(): bool
    {
        if (in_array($this->status, [RequestStatus::ReadyForReview, RequestStatus::RevisionRequested], true)) {
            return true;
        }

        if (! in_array($this->status, [RequestStatus::PaymentConfirmed, RequestStatus::InProgress], true)) {
            return false;
        }

        $delivered = $this->drive_deliveries_count ?? null;
        if ($delivered !== null) {
            return (int) $delivered > 0;
        }

        return $this->driveDeliveries()->exists();
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

    /**
     * @return list<string>
     */
    public function plannedDepartments(): array
    {
        $operations = data_get($this->work_plan, 'operations');
        if (is_array($operations) && $operations !== []) {
            $departments = [];
            foreach ($operations as $operation) {
                if (! is_array($operation)) {
                    continue;
                }
                $department = trim((string) ($operation['department'] ?? ''));
                if ($department !== '' && ! in_array($department, $departments, true)) {
                    $departments[] = $department;
                }
            }

            if ($departments !== []) {
                return $departments;
            }
        }

        return $this->work_type?->requiredClickUpTaskTypes() ?? [];
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
