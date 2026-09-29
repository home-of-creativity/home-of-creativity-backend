<?php

namespace App\Support;

class PhotographyChime
{
    public static function path(): string
    {
        $path = storage_path('app/photography-chime.wav');
        if (is_file($path)) {
            return $path;
        }

        $rate = 22050;
        $notes = [523.25, 659.25, 783.99, 1046.5];
        $pcm = '';
        foreach ($notes as $frequency) {
            $count = (int) (0.32 * $rate);
            for ($i = 0; $i < $count; $i++) {
                $time = $i / $rate;
                $envelope = exp(-3.2 * $time);
                $sample = (int) (sin(2 * M_PI * $frequency * $time) * $envelope * 12000);
                $pcm .= pack('v', $sample & 0xFFFF);
            }
        }

        $size = strlen($pcm);
        $header = 'RIFF'.pack('V', 36 + $size).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', $size);
        file_put_contents($path, $header.$pcm);

        return $path;
    }
}
