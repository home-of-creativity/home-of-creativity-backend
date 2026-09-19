<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class VideoFaststart
{
    /**
     * Move the MP4 index (moov atom) to the front of the file so a browser can
     * start playing while it downloads instead of pulling the whole clip first.
     * Stream copy only — no re-encode, no quality loss. Silently skipped when
     * ffmpeg is missing or the file already starts with its index.
     */
    public static function apply(?string $path, string $disk = 'public'): void
    {
        if (! $path || ! preg_match('/\.(mp4|m4v|mov)$/i', $path)) {
            return;
        }

        $storage = Storage::disk($disk);

        if (! $storage->exists($path) || ! self::needsRemux($storage->path($path))) {
            return;
        }

        $source = $storage->path($path);
        $target = $source.'.faststart.mp4';

        $result = Process::timeout(600)->run([
            config('services.ffmpeg.binary'),
            '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $source,
            '-map', '0', '-c', 'copy',
            '-movflags', '+faststart',
            $target,
        ]);

        if (! $result->successful() || ! is_file($target) || filesize($target) === 0) {
            @unlink($target);
            Log::warning('reel faststart remux failed', ['path' => $path, 'error' => $result->errorOutput()]);

            return;
        }

        @rename($target, $source);
    }

    /**
     * True when the file is an MP4 whose moov atom sits after the media data.
     */
    public static function needsRemux(string $absolute): bool
    {
        $handle = @fopen($absolute, 'rb');

        if (! $handle) {
            return false;
        }

        $moov = null;
        $mdat = null;
        $offset = 0;
        $size = filesize($absolute) ?: 0;
        $isMp4 = false;

        while ($offset < $size) {
            if (fseek($handle, $offset) !== 0) {
                break;
            }

            $header = fread($handle, 8);

            if ($header === false || strlen($header) < 8) {
                break;
            }

            /** @var array{size: int} $parsed */
            $parsed = unpack('Nsize', substr($header, 0, 4));
            $boxSize = $parsed['size'];
            $type = substr($header, 4, 4);

            if ($type === 'ftyp') {
                $isMp4 = true;
            }

            if ($type === 'moov' && $moov === null) {
                $moov = $offset;
            }

            if ($type === 'mdat' && $mdat === null) {
                $mdat = $offset;
            }

            if ($moov !== null && $mdat !== null) {
                break;
            }

            if ($boxSize === 1) {
                $extended = fread($handle, 8);

                if ($extended === false || strlen($extended) < 8) {
                    break;
                }

                /** @var array{high: int, low: int} $large */
                $large = unpack('Nhigh/Nlow', $extended);
                $boxSize = ($large['high'] << 32) + $large['low'];
            }

            if ($boxSize < 8) {
                break;
            }

            $offset += $boxSize;
        }

        fclose($handle);

        return $isMp4 && $moov !== null && $mdat !== null && $moov > $mdat;
    }
}
