<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** A receipt is a jpeg, png, webp, or pdf. The declared type has to match the file bytes. */
class ClientUploadGuard
{
    public function assertReceipt(string $binary, string $mime): string
    {
        if (strlen($binary) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['file_base64' => 'File exceeds 5MB limit.']);
        }

        $detected = $this->detect($binary);
        $mime = strtolower(trim(explode(';', $mime)[0] ?? ''));
        if ($detected === null || $detected !== $mime) {
            throw ValidationException::withMessages(['mime_type' => 'Unsupported file type.']);
        }

        return $detected;
    }

    public function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    private function detect(string $binary): ?string
    {
        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($binary, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (strlen($binary) >= 12 && str_starts_with($binary, 'RIFF') && substr($binary, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (str_starts_with($binary, '%PDF')) {
            return 'application/pdf';
        }

        return null;
    }
}
