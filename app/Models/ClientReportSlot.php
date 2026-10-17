<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ClientReportSlot extends Model
{
    protected $fillable = [
        'client_id',
        'kind',
        'scheduled_at',
        'caption',
        'image_path',
        'image_name',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (ClientReportSlot $slot): void {
            if (filled($slot->image_path)) {
                Storage::disk('local')->delete($slot->image_path);
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
