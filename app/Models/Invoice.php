<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'request_id',
        'invoice_number',
        'amount',
        'kind',
        'status',
        'subscription_id',
        'pdf_path',
        'telegram_file_id',
        'issued_at',
        'payment_method',
        'odoo_invoice_id',
    ];

    protected $attributes = [
        'kind' => 'full',
        'status' => 'issued',
    ];

    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'issued_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * What a new Odoo invoice is to its request: the first one short of the total is the
     * down payment, a later one the remainder, otherwise the full amount.
     */
    public static function kindFor(ServiceRequest $request, float $amount): string
    {
        if ($request->invoices()->whereNotNull('odoo_invoice_id')->whereIn('kind', ['full', 'deposit', 'remaining'])->exists()) {
            return 'remaining';
        }
        $total = (float) ($request->amount_total ?? $request->quotation_amount ?? 0);

        return $total > 0 && $amount < $total - 0.009 ? 'deposit' : 'full';
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
