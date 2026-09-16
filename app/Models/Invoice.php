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

    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
