<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DevHealth
{
    public function labelUrl(): string
    {
        return (string) config('services.dev.health_url');
    }

    public function up(): bool
    {
        $internal = trim((string) config('services.dev.health_internal_url'));
        $public = trim($this->labelUrl());

        if ($internal !== '' && $this->probe($internal)) {
            return true;
        }

        if ($public !== '' && $public !== $internal) {
            return $this->probe($public);
        }

        return false;
    }

    private function probe(string $url): bool
    {
        try {
            return Http::timeout(8)->connectTimeout(3)->get($url)->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
