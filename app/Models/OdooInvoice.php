<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A local copy of one Odoo customer invoice. `odoo:sync-invoices` keeps it current,
 * so the finance page reads Odoo's state without waiting on Odoo.
 */
class OdooInvoice extends Model
{
    protected $fillable = [
        'odoo_id',
        'name',
        'partner_id',
        'partner_name',
        'client_id',
        'request_id',
        'amount_total',
        'amount_residual',
        'currency',
        'state',
        'payment_state',
        'invoice_date',
        'invoice_date_due',
        'invoice_origin',
        'ref',
        'odoo_write_date',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_total' => 'decimal:2',
            'amount_residual' => 'decimal:2',
            'invoice_date' => 'date',
            'invoice_date_due' => 'date',
            'odoo_write_date' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * paid, partial, open, overdue, draft, or cancelled.
     */
    public function bucket(): string
    {
        $state = (string) $this->state;
        $payment = (string) $this->payment_state;

        return match (true) {
            $state === 'cancel' => 'cancelled',
            $state === 'draft' => 'draft',
            in_array($payment, ['paid', 'in_payment', 'reversed'], true) => 'paid',
            $this->isOverdue() => 'overdue',
            $payment === 'partial' => 'partial',
            default => 'open',
        };
    }

    public function isOverdue(): bool
    {
        return $this->state === 'posted'
            && (float) $this->amount_residual > 0.009
            && $this->invoice_date_due !== null
            && $this->invoice_date_due->lt(now('Asia/Damascus')->startOfDay());
    }

    public function collected(): float
    {
        if ($this->state !== 'posted') {
            return 0.0;
        }

        return round(max((float) $this->amount_total - (float) $this->amount_residual, 0), 2);
    }
}
