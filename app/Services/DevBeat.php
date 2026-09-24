<?php

namespace App\Services;

class DevBeat
{
    public function touch(string $name): void
    {
        $path = $this->path($name);
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        touch($path);
    }

    public function ageSeconds(string $name): ?int
    {
        $path = $this->path($name);
        if (! is_file($path)) {
            return null;
        }

        clearstatcache(true, $path);

        return max(0, time() - (int) filemtime($path));
    }

    public function read(string $name): ?string
    {
        $path = $this->path($name);
        if (! is_file($path)) {
            return null;
        }

        $text = trim((string) file_get_contents($path));

        return $text === '' ? null : $text;
    }

    public function path(string $name): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/', '', $name) ?: 'unknown';

        return storage_path('app/dev-beats/'.$safe);
    }
}
