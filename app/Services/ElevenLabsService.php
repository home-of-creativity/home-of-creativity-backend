<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ElevenLabsService
{
    public function configured(): bool
    {
        return filled(config('services.elevenlabs.api_key'));
    }

    public function transcribe(string $absolutePath): ?string
    {
        if (! $this->configured() || ! is_file($absolutePath)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'xi-api-key' => (string) config('services.elevenlabs.api_key'),
            ])
                ->timeout((int) config('services.elevenlabs.timeout', 60))
                ->connectTimeout(5)
                ->attach('file', fopen($absolutePath, 'r'), basename($absolutePath))
                ->post('https://api.elevenlabs.io/v1/speech-to-text', [
                    'model_id' => 'scribe_v1',
                ]);

            if (! $response->successful()) {
                Log::warning('ElevenLabs transcription failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $text = trim((string) (
                $response->json('text')
                ?? $response->json('transcript')
                ?? ''
            ));

            return $text !== '' ? $text : null;
        } catch (Throwable $exception) {
            Log::warning('ElevenLabs transcription exception.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
