<?php

namespace App\Support;

use App\Models\OpsSetting;
use Illuminate\Support\Facades\Storage;

class ShamCashQr
{
    public static function relativePath(): ?string
    {
        $path = OpsSetting::getValue('sham_cash_qr_path');
        if (! filled($path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return $path;
    }

    public static function absolutePath(): ?string
    {
        $path = self::relativePath();
        if ($path === null) {
            return null;
        }

        $absolute = Storage::disk('local')->path($path);

        return is_file($absolute) ? $absolute : null;
    }

    /**
     * @param  array{caption: string, qr_available: bool, delivered: bool}  $notice
     * @return array{caption: string, qr_available: bool, delivered: bool, file_name: string|null, mime_type: string|null, content_base64: string|null}
     */
    public static function toBotPayload(array $notice): array
    {
        $payload = [
            'caption' => $notice['caption'],
            'qr_available' => (bool) $notice['qr_available'],
            'delivered' => (bool) $notice['delivered'],
            'file_name' => null,
            'mime_type' => null,
            'content_base64' => null,
        ];

        if (! $payload['qr_available'] || $payload['delivered']) {
            return $payload;
        }

        $absolute = self::absolutePath();
        if ($absolute === null) {
            $payload['qr_available'] = false;

            return $payload;
        }

        $bytes = file_get_contents($absolute);
        if (! is_string($bytes) || $bytes === '') {
            $payload['qr_available'] = false;

            return $payload;
        }

        $payload['file_name'] = basename($absolute);
        $payload['mime_type'] = mime_content_type($absolute) ?: 'image/png';
        $payload['content_base64'] = base64_encode($bytes);

        return $payload;
    }
}
