<?php

namespace App\Services;

use App\Enums\WorkType;
use App\Models\DepartmentBrief;
use App\Models\ServiceRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class GeminiService
{
    /**
     * @return array{work_type: WorkType, briefs: list<array{type: string, brief: string}>}
     */
    public function classify(string $title, string $description): array
    {
        if (config('services.gemini.e2e_stub')) {
            return [
                'work_type' => WorkType::Design,
                'briefs' => [['type' => 'design', 'brief' => "موجز تجريبي للمهمة: {$title}"]],
            ];
        }

        $this->requireApiKey();

        $prompt = <<<PROMPT
Analyze this creative service request and return ONLY valid JSON with this exact shape:
{"work_type":"design|content|both","briefs":[{"type":"design|content","brief":"..."}]}

Rules:
- work_type must be exactly one of: design, content, both
- If work_type is design, briefs must contain exactly one item with type design
- If work_type is content, briefs must contain exactly one item with type content
- If work_type is both, briefs must contain one design and one content item
- brief text must be actionable for the assigned team
- Write all brief text in Arabic (العربية).

Request title: {$title}
Request description: {$description}
PROMPT;

        $response = $this->generateJson($prompt);

        if (! $response->successful()) {
            $apiMessage = (string) data_get($response->json(), 'error.message', $response->body());
            Log::warning('Gemini classification failed.', [
                'status' => $response->status(),
                'model' => config('services.gemini.model'),
                'body' => $response->body(),
            ]);

            throw ValidationException::withMessages([
                'gemini' => $this->failedClassificationMessage($apiMessage),
            ]);
        }

        $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));
        $text = $this->extractJsonText($text);
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            Log::warning('Gemini returned invalid JSON.', [
                'model' => config('services.gemini.model'),
                'text' => $text,
            ]);

            throw ValidationException::withMessages([
                'gemini' => 'Gemini returned invalid JSON.',
            ]);
        }

        return $this->translateBriefs($this->validatePayload($decoded));
    }

    public function classifyCompanyIndustry(string $companyName): ?string
    {
        $companyName = trim($companyName);
        if ($companyName === '') {
            return null;
        }

        if (config('services.gemini.e2e_stub')) {
            return 'خدمات عامة';
        }

        if ($this->apiKey() === '') {
            return null;
        }

        try {
            $prompt = <<<PROMPT
Classify the industry of this company in one short Arabic tag (2-5 words).
Return ONLY valid JSON: {"industry":"..."}
Write all brief text in Arabic (العربية).

Company name: {$companyName}
PROMPT;

            $response = $this->generateJson($prompt);

            if (! $response->successful()) {
                Log::warning('Gemini industry classification failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));
            $text = $this->extractJsonText($text);
            $decoded = json_decode($text, true);
            if (! is_array($decoded)) {
                return null;
            }

            $industry = trim((string) ($decoded['industry'] ?? ''));

            return $industry !== '' ? $industry : null;
        } catch (\Throwable $exception) {
            Log::warning('Gemini industry classification exception.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{work_type: WorkType, briefs: list<array{type: string, brief: string}>}
     */
    public function validatePayload(array $payload): array
    {
        $workType = WorkType::tryFrom((string) ($payload['work_type'] ?? ''));
        if (! $workType instanceof WorkType) {
            throw ValidationException::withMessages([
                'gemini' => 'Invalid work_type from Gemini.',
            ]);
        }

        $briefs = $payload['briefs'] ?? null;
        if (! is_array($briefs) || $briefs === []) {
            throw ValidationException::withMessages([
                'gemini' => 'Gemini briefs are missing.',
            ]);
        }

        $normalized = [];
        foreach ($briefs as $brief) {
            if (! is_array($brief)) {
                throw ValidationException::withMessages(['gemini' => 'Invalid brief structure.']);
            }
            $type = (string) ($brief['type'] ?? '');
            if (! in_array($type, ['design', 'content'], true)) {
                throw ValidationException::withMessages(['gemini' => 'Invalid brief type.']);
            }
            $text = trim((string) ($brief['brief'] ?? ''));
            if ($text === '') {
                throw ValidationException::withMessages(['gemini' => 'Brief text is required.']);
            }
            $normalized[] = ['type' => $type, 'brief' => $text];
        }

        $types = array_column($normalized, 'type');
        foreach ($workType->requiredBriefTypes() as $required) {
            if (! in_array($required, $types, true)) {
                throw ValidationException::withMessages([
                    'gemini' => "Missing required brief type: {$required}.",
                ]);
            }
        }

        if ($workType === WorkType::Design && count($normalized) !== 1) {
            throw ValidationException::withMessages(['gemini' => 'Design work_type requires exactly one brief.']);
        }
        if ($workType === WorkType::Content && count($normalized) !== 1) {
            throw ValidationException::withMessages(['gemini' => 'Content work_type requires exactly one brief.']);
        }
        if ($workType === WorkType::Both && count($normalized) < 2) {
            throw ValidationException::withMessages(['gemini' => 'Both work_type requires design and content briefs.']);
        }

        return ['work_type' => $workType, 'briefs' => $normalized];
    }

    /**
     * @param  list<array{type: string, brief: string}>  $briefs
     */
    public function persistBriefs(ServiceRequest $request, array $briefs): void
    {
        $briefs = app(GoogleTranslateService::class)->briefsToArabic($briefs);

        foreach ($briefs as $brief) {
            DepartmentBrief::query()->firstOrCreate(
                [
                    'request_id' => $request->id,
                    'type' => $brief['type'],
                ],
                [
                    'department' => $brief['type'],
                    'brief' => $brief['brief'],
                ],
            );
        }
    }

    /**
     * @param  array{work_type: WorkType, briefs: list<array{type: string, brief: string}>}  $payload
     * @return array{work_type: WorkType, briefs: list<array{type: string, brief: string}>}
     */
    private function translateBriefs(array $payload): array
    {
        $payload['briefs'] = app(GoogleTranslateService::class)->briefsToArabic($payload['briefs']);

        return $payload;
    }

    private function generateJson(string $prompt): Response
    {
        $model = (string) config('services.gemini.model', 'gemini-2.5-flash');

        return Http::timeout((int) config('services.gemini.timeout', 30))
            ->connectTimeout(5)
            ->acceptJson()
            ->withHeaders(['x-goog-api-key' => $this->apiKey()])
            ->post($this->generateContentUrl($model), [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'responseMimeType' => 'application/json',
                ],
            ]);
    }

    private function generateContentUrl(string $model): string
    {
        $base = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');

        return "{$base}/models/{$model}:generateContent";
    }

    private function apiKey(): string
    {
        return trim((string) config('services.gemini.api_key'));
    }

    private function requireApiKey(): void
    {
        if ($this->apiKey() !== '') {
            return;
        }

        throw ValidationException::withMessages([
            'gemini' => 'Gemini API key is not configured. Set GEMINI_API_KEY to an AI Studio auth key restricted to the Gemini API.',
        ]);
    }

    private function failedClassificationMessage(string $apiMessage): string
    {
        if (str_contains($apiMessage, 'are blocked') || str_contains($apiMessage, 'API_KEY_SERVICE_BLOCKED')) {
            return 'Gemini classification failed: this API key is blocked for Gemini. Create an AI Studio auth key restricted to the Gemini API and set GEMINI_API_KEY (do not reuse a Maps or unrestricted standard GOOGLE_API_KEY).';
        }

        return 'Gemini classification failed: '.$apiMessage;
    }

    private function extractJsonText(string $text): string
    {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return $text;
    }
}
