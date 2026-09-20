<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class DriveDelivery extends Model
{
    protected $fillable = [
        'request_id',
        'drive_file_id',
        'name',
        'mime_type',
        'sent_at',
        'client_approved_at',
        'drive_modified_at',
        'content_hash',
        'telegram_message_id',
        'failed_at',
        'fail_reason',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'client_approved_at' => 'datetime',
            'drive_modified_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_id');
    }

    public function clientDeliveryStatus(): string
    {
        if ($this->sent_at !== null) {
            return 'sent';
        }

        if ($this->failed_at !== null) {
            return 'failed';
        }

        return 'pending';
    }

    public function clientDeliveryLabelAr(): string
    {
        return match ($this->clientDeliveryStatus()) {
            'sent' => 'وصل للعميل',
            'failed' => 'فشل الإرسال',
            default => 'لم يصل بعد',
        };
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function clientRevisionKeyboard(string $requestRef, bool $canComplete = false): array
    {
        $row = [
            ['text' => '✏️ تعديل', 'callback_data' => 'revfile:'.$requestRef.':'.$this->id],
        ];
        if ($this->client_approved_at === null) {
            $row[] = ['text' => '✅ موافقة', 'callback_data' => 'okfile:'.$requestRef.':'.$this->id];
        }

        $rows = [$row];
        if ($canComplete) {
            $rows[] = [['text' => '✅ اعتماد التسليم', 'callback_data' => 'complete:'.$requestRef]];
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function clientReviewKeyboard(string $requestRef, bool $canComplete = true): array
    {
        if (! $canComplete) {
            return ['inline_keyboard' => []];
        }

        return [
            'inline_keyboard' => [
                [['text' => '✅ اعتماد التسليم', 'callback_data' => 'complete:'.$requestRef]],
            ],
        ];
    }

    public static function normalizedName(string $name): string
    {
        return mb_strtolower(trim(basename($name)));
    }

    public static function matchIncoming(int $requestId, string $fileId, string $name): self
    {
        $byId = static::query()->where('drive_file_id', $fileId)->first();
        if ($byId instanceof self) {
            return $byId;
        }

        $normalized = static::normalizedName($name);
        if ($normalized !== '') {
            $byName = static::query()
                ->where('request_id', $requestId)
                ->orderByDesc('id')
                ->get()
                ->first(fn (self $row): bool => static::normalizedName((string) $row->name) === $normalized);
            if ($byName instanceof self) {
                return $byName;
            }
        }

        return new self([
            'drive_file_id' => $fileId,
            'request_id' => $requestId,
        ]);
    }

    public function wasAlreadySent(): bool
    {
        return $this->exists && $this->sent_at !== null;
    }

    public function clientSendCaption(string $requestRef): string
    {
        $name = (string) ($this->name ?: 'ملف');
        if ($this->wasAlreadySent()) {
            return 'تم تعديل الملف «'.$name.'» للطلب #'.$requestRef." وأُرسل من جديد.\nإذا كانت جاهزة اضغط موافقة، أو اطلب تعديلاً.";
        }

        return 'ملف جديد للطلب #'.$requestRef.': '.$name."\nإذا كانت جاهزة اضغط موافقة، أو اطلب تعديلاً.";
    }

    /**
     * @param  array{id?: string, modifiedTime?: string|null, md5Checksum?: string|null}  $file
     */
    public function needsResend(?string $modifiedTime, ?string $remoteHash, ?string $localHash): bool
    {
        if ($this->failed_at !== null) {
            return false;
        }

        if ($this->sent_at === null) {
            return true;
        }

        if ($localHash !== null && $this->content_hash) {
            return ! hash_equals((string) $this->content_hash, $localHash);
        }

        // First hash snapshot after a file was already delivered: record it,
        // do not treat the missing column as a content change.
        if ($localHash !== null && ! $this->content_hash) {
            return false;
        }

        if ($this->sameSecond($modifiedTime)) {
            return false;
        }

        if ($modifiedTime !== null) {
            $remote = Carbon::parse($modifiedTime)->utc()->startOfSecond();
            $stored = $this->drive_modified_at?->copy()->utc()->startOfSecond();
            if ($stored === null) {
                return false;
            }
            if ($remote->gt($stored)) {
                return true;
            }
        }

        return false;
    }

    private function sameSecond(?string $modifiedTime): bool
    {
        if ($modifiedTime === null || $this->drive_modified_at === null) {
            return false;
        }

        $remote = Carbon::parse($modifiedTime)->utc()->startOfSecond();
        $stored = $this->drive_modified_at->copy()->utc()->startOfSecond();

        return $remote->equalTo($stored);
    }
}
