<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleTranslateService
{
    public function toArabic(string $text): string
    {
        $text = trim($text);
        if ($text === '' || $this->looksArabic($text) || ! config('services.google_translate.enabled')) {
            return $text;
        }

        try {
            $official = $this->viaCloudTranslation($text);
            if ($official !== null) {
                return $official;
            }

            $gtx = $this->viaGoogleTranslate($text);
            if ($gtx !== null) {
                return $gtx;
            }
        } catch (Throwable $exception) {
            Log::warning('Google Translate failed.', [
                'error' => $exception->getMessage(),
            ]);
        }

        return $text;
    }

    /**
     * @param  list<array{type: string, brief: string}>  $briefs
     * @return list<array{type: string, brief: string}>
     */
    public function briefsToArabic(array $briefs): array
    {
        return array_values(array_map(function (array $brief): array {
            $brief['brief'] = $this->toArabic((string) ($brief['brief'] ?? ''));

            return $brief;
        }, $briefs));
    }

    public function looksArabic(string $text): bool
    {
        return preg_match('/\p{Arabic}/u', $text) === 1;
    }

    private function viaCloudTranslation(string $text): ?string
    {
        $apiKey = trim((string) config('services.google_translate.api_key'));
        if ($apiKey === '') {
            return null;
        }

        $response = Http::timeout((int) config('services.google_translate.timeout', 12))
            ->connectTimeout(4)
            ->acceptJson()
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->post('https://translation.googleapis.com/language/translate/v2', [
                'q' => $text,
                'target' => 'ar',
                'format' => 'text',
            ]);

        if (! $response->successful()) {
            Log::info('Cloud Translation skipped.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $translated = html_entity_decode(
            trim((string) data_get($response->json(), 'data.translations.0.translatedText', '')),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        return $translated !== '' ? $translated : null;
    }

    private function viaGoogleTranslate(string $text): ?string
    {
        $response = Http::timeout((int) config('services.google_translate.timeout', 12))
            ->connectTimeout(4)
            ->asForm()
            ->acceptJson()
            ->post('https://translate.googleapis.com/translate_a/single', [
                'client' => 'gtx',
                'sl' => 'auto',
                'tl' => 'ar',
                'dt' => 't',
                'q' => $text,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $chunks = data_get($response->json(), '0');
        if (! is_array($chunks)) {
            return null;
        }

        $parts = [];
        foreach ($chunks as $chunk) {
            if (is_array($chunk) && isset($chunk[0]) && is_string($chunk[0]) && $chunk[0] !== '') {
                $parts[] = $chunk[0];
            }
        }

        $translated = trim(implode('', $parts));

        return $translated !== '' ? $translated : null;
    }
}
