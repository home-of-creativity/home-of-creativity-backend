<?php

namespace App\Actions;

use App\Models\RequestFile;
use App\Models\ServiceRequest;
use App\Services\ElevenLabsService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StoreRequestAttachments
{
    private const MAX_FILES = 5;

    private const MAX_BYTES = 5 * 1024 * 1024;

    /** @var list<string> */
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
        'audio/ogg',
        'audio/mpeg',
        'audio/mp4',
        'audio/x-m4a',
    ];

    /**
     * @param  list<array{file_name: string, file_base64: string, mime_type?: string|null}>  $attachments
     */
    public function handle(ServiceRequest $request, array $attachments, string $kind = 'brief_attachment'): void
    {
        if ($attachments === []) {
            return;
        }

        if (count($attachments) > self::MAX_FILES) {
            throw ValidationException::withMessages([
                'attachments' => 'You can attach up to '.self::MAX_FILES.' files.',
            ]);
        }

        foreach ($attachments as $index => $attachment) {
            $binary = base64_decode($attachment['file_base64'], true);
            if ($binary === false) {
                throw ValidationException::withMessages([
                    "attachments.{$index}.file_base64" => 'Invalid file payload.',
                ]);
            }

            if (strlen($binary) > self::MAX_BYTES) {
                throw ValidationException::withMessages([
                    "attachments.{$index}.file_base64" => 'File exceeds 5MB limit.',
                ]);
            }

            $mime = $attachment['mime_type'] ?? 'application/octet-stream';
            if (! in_array($mime, self::ALLOWED_MIMES, true)) {
                throw ValidationException::withMessages([
                    "attachments.{$index}.mime_type" => 'Unsupported file type.',
                ]);
            }

            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'application/pdf' => 'pdf',
                'audio/ogg' => 'ogg',
                'audio/mpeg' => 'mp3',
                'audio/mp4', 'audio/x-m4a' => 'm4a',
                default => 'bin',
            };

            $path = "request-attachments/{$request->number}-".now()->format('YmdHis')."-{$index}.{$extension}";
            Storage::disk('local')->put($path, $binary);

            if (str_starts_with($mime, 'audio/')) {
                $absolute = Storage::disk('local')->path($path);
                $transcript = app(ElevenLabsService::class)->transcribe($absolute);
                if (filled($transcript) && ! filled($request->description)) {
                    $request->forceFill(['description' => $transcript])->save();
                } elseif (filled($transcript)) {
                    $request->forceFill([
                        'description' => trim($request->description."\n\nتفريغ صوتي: {$transcript}"),
                    ])->save();
                }
            }

            RequestFile::query()->create([
                'request_id' => $request->id,
                'kind' => $kind,
                'original_name' => $attachment['file_name'],
                'path' => $path,
            ]);
        }
    }
}
