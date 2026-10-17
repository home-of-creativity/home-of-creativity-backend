<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class ClientReportSlot extends Model
{
    protected $fillable = [
        'client_id',
        'report_calendar_id',
        'kind',
        'color',
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
            $disk = Storage::disk('local');
            if (filled($slot->image_path)) {
                $disk->delete($slot->image_path);
            }
            foreach ($slot->images()->pluck('path') as $path) {
                if (filled($path)) {
                    $disk->delete($path);
                }
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(ReportCalendar::class, 'report_calendar_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ClientReportSlotImage::class)->orderBy('position')->orderBy('id');
    }
}
