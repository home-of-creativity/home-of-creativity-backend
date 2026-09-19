<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpsExpense extends Model
{
    protected $table = 'ops_expenses';

    protected $fillable = [
        'amount',
        'category',
        'note',
        'created_by_telegram_id',
        'spent_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'spent_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public static function categories(): array
    {
        return ['رواتب', 'إعلانات', 'برامج', 'مكتب', 'تنقل', 'أخرى'];
    }
}
