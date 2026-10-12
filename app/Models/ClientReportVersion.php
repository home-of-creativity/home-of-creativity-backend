<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientReportVersion extends Model
{
    public const CREATED = 'created';

    public const EDITED = 'edited';

    public const PUBLISHED = 'published';

    public const RESTORED = 'restored';

    public const ATTACHMENT_ADDED = 'attachment_added';

    public const ATTACHMENT_REMOVED = 'attachment_removed';

    public const DELETED = 'deleted';

    protected $fillable = [
        'client_id',
        'client_report_id',
        'user_id',
        'event',
        'title',
        'body',
        'document_path',
        'size',
        'saves',
        'detail',
        'started_at',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'started_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ClientReport::class, 'client_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
