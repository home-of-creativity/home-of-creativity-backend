<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialVoucher extends Model
{
    /** @var list<string> */
    public const KINDS = ['receipt', 'payment', 'journal', 'settlement'];

    /** @var list<string> */
    public const CURRENCIES = ['USD', 'SYP'];

    protected $fillable = [
        'serial',
        'kind',
        'party_name',
        'amount',
        'currency',
        'amount_words',
        'issued_on',
        'purpose',
        'reference',
        'lines',
        'signer_name',
        'counter_signer_name',
        'signature',
        'counter_signature',
        'signed_at',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'issued_on' => 'date:Y-m-d',
            'lines' => 'array',
            'signed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
