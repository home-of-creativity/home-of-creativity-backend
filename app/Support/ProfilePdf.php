<?php

namespace App\Support;

use App\Models\OpsSetting;
use Illuminate\Support\Facades\Storage;

class ProfilePdf
{
    public static function relativePath(): ?string
    {
        $path = OpsSetting::getValue('profile_pdf_path');
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

    public static function originalName(): ?string
    {
        if (self::relativePath() === null) {
            return null;
        }

        $name = OpsSetting::getValue('profile_pdf_name');

        return filled($name) ? (string) $name : 'profile.pdf';
    }

    public static function downloadName(): string
    {
        $name = str_replace(['\\', '/', '"', "\r", "\n"], '-', self::originalName() ?? 'profile.pdf');
        $name = trim($name, '.-') ?: 'profile.pdf';

        return str_ends_with(strtolower($name), '.pdf') ? $name : $name.'.pdf';
    }

    /**
     * @return array{url: string|null, name: string|null, updated_at: string|null}
     */
    public static function payload(): array
    {
        $path = self::relativePath();

        return [
            'url' => $path === null ? null : url('/api/profile-pdf/file'),
            'name' => $path === null ? null : self::originalName(),
            'updated_at' => $path === null ? null : OpsSetting::getValue('profile_pdf_updated_at'),
        ];
    }
}
