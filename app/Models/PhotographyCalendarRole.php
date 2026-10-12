<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotographyCalendarRole extends Model
{
    protected $fillable = [
        'calendar_id',
        'role_id',
        'can_edit',
    ];

    protected function casts(): array
    {
        return [
            'can_edit' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
