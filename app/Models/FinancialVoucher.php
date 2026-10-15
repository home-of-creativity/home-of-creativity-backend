<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialVoucher extends Model
{
    /** @var list<string> */
    public const KINDS = ['delivery', 'receipt', 'payment', 'journal', 'settlement', 'notes'];

    /**
     * Lines a kind may hold and the length of each line's text. A notes record (minutes of a
     * conversation) holds longer and more lines than a money voucher.
     *
     * @return array{0: int, 1: int}
     */
    public static function lineLimits(?string $kind): array
    {
        return $kind === 'notes' ? [40, 1000] : [12, 180];
    }

    /** @var list<string> */
    public const CURRENCIES = ['USD', 'SYP'];

    /** Next unused HOC-V-{year}-{####} for this calendar year (max suffix + 1, not a row count). */
    public static function nextSerial(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = sprintf('HOC-V-%d-', $year);
        $last = static::query()
            ->where('serial', 'like', $prefix.'%')
            ->orderByDesc('serial')
            ->value('serial');
        $n = 1;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $match) === 1) {
            $n = ((int) $match[1]) + 1;
        }

        return sprintf('HOC-V-%d-%04d', $year, $n);
    }

    /**
     * Turns "7", "N0007", or "HOC-V-2026-0007" into a stored serial for $year.
     */
    public static function normalizeSerial(string $value, ?int $year = null): string
    {
        $value = trim($value);
        $year ??= (int) now()->format('Y');
        if (preg_match('/^HOC-V-\d{4}-\d{1,6}$/i', $value) === 1) {
            if (preg_match('/^HOC-V-(\d{4})-(\d+)$/i', $value, $match) === 1) {
                return sprintf('HOC-V-%d-%04d', (int) $match[1], (int) $match[2]);
            }
        }
        if (preg_match('/^N?0*(\d{1,6})$/i', $value, $match) === 1) {
            return sprintf('HOC-V-%d-%04d', $year, (int) $match[1]);
        }

        return mb_substr($value, 0, 24);
    }

    protected $fillable = [
        'serial',
        'kind',
        'title',
        'party_name',
        'amount',
        'remaining',
        'show_remaining',
        'currency',
        'amount_words',
        'issued_on',
        'purpose',
        'reference',
        'lines',
        'signer_name',
        'signer_title',
        'counter_signer_name',
        'counter_signer_title',
        'signature',
        'counter_signature',
        'background',
        'signed_at',
        'sent_at',
        'sent_to',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'remaining' => 'decimal:2',
            'show_remaining' => 'boolean',
            'issued_on' => 'date:Y-m-d',
            'lines' => 'array',
            'signed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
