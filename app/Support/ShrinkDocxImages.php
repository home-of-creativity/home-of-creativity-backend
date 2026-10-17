<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Shrink pictures inside a saved report.docx. The original is copied beside the file first.
 * A failure leaves the original in place and does not touch the report row.
 */
class ShrinkDocxImages
{
    public const MAX_EDGE = 2000;

    public function shrink(string $disk, string $path): bool
    {
        if (! function_exists('imagecreatefromstring') || ! class_exists(ZipArchive::class)) {
            return false;
        }

        $store = Storage::disk($disk);
        if (! $store->exists($path)) {
            return false;
        }

        $binary = $store->get($path);
        if (! is_string($binary) || $binary === '') {
            return false;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'hoc-docx');
        if ($tmp === false) {
            return false;
        }
        file_put_contents($tmp, $binary);

        $zip = new ZipArchive;
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);

            return false;
        }

        $changed = false;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (! str_starts_with($name, 'word/media/')) {
                continue;
            }
            $bytes = $zip->getFromIndex($index);
            if (! is_string($bytes)) {
                continue;
            }
            $next = $this->shrinkBytes($bytes);
            if ($next === null) {
                continue;
            }
            $zip->addFromString($name, $next);
            $changed = true;
        }
        $zip->close();

        if (! $changed) {
            @unlink($tmp);

            return false;
        }

        $backup = $path.'.bak';
        if (! $store->exists($backup)) {
            $store->put($backup, $binary);
        }

        $rewritten = file_get_contents($tmp);
        @unlink($tmp);
        if (! is_string($rewritten) || $rewritten === '') {
            return false;
        }

        $store->put($path, $rewritten);

        return true;
    }

    private function shrinkBytes(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        if (max($width, $height) <= self::MAX_EDGE) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        $scale = self::MAX_EDGE / max($width, $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            imagedestroy($image);

            return null;
        }

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($image);

        ob_start();
        $mime = (string) ($info['mime'] ?? '');
        if ($mime === 'image/png') {
            imagepng($canvas, null, 6);
        } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
            imagewebp($canvas, null, 85);
        } else {
            imagejpeg($canvas, null, 85);
        }
        $out = ob_get_clean();
        imagedestroy($canvas);

        return is_string($out) && $out !== '' ? $out : null;
    }
}
