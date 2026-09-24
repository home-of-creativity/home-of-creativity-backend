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
        $internal = (string) config('services.dev.health_internal_url');
        $url = $internal !== '' ? $internal : $this->labelUrl();

        try {
            return Http::timeout(4)->connectTimeout(2)->get($url)->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
