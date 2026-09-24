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

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return array{work_type: WorkType, briefs: list<array{type: string, brief: string}>}
     */
    public function classificationFromWorkPlan(array $operations): array
    {
        $briefs = [];
        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }
            $type = trim((string) ($operation['department'] ?? ''));
            $brief = trim((string) ($operation['brief'] ?? ''));
            if ($type === '' || $brief === '') {
                continue;
            }
            $briefs[] = ['type' => $type, 'brief' => $brief];
        }

        if ($briefs === []) {
            $briefs[] = ['type' => 'design', 'brief' => 'تنفيذ العمل المطلوب'];
        }

        $types = array_column($briefs, 'type');
        $hasDesign = in_array('design', $types, true);
        $hasContent = in_array('content', $types, true);
        $workType = match (true) {
            $hasDesign && $hasContent => WorkType::Both,
            $hasContent && ! $hasDesign => WorkType::Content,
            default => WorkType::Design,
        };

        return [
            'work_type' => $workType,
            'briefs' => $briefs,
        ];
    }

    /**
     * @return list<array{department: string, brief: string, hours: int, priority: int}>
     */
    public function planWork(string $title, string $description, ?string $packageContext = null): array
    {
        if (config('services.gemini.e2e_stub')) {
            return [[
                'department' => 'design',
                'brief' => "تنفيذ الطلب: {$title}",
                'hours' => 24,
                'priority' => 3,
            ]];
        }

        if ($this->apiKey() === '') {
            return $this->heuristicPlan($title, $description, $packageContext);
        }

        $packageBlock = filled($packageContext) ? "Package / catalog context:\n{$packageContext}\n" : "This is a manual request (no catalog package).\n";

        $prompt = <<<PROMPT
Plan the production work for this creative-agency request. Return ONLY valid JSON:
{"operations":[{"department":"design|content|programming|photography","brief":"...","hours":8,"priority":3}]}

Rules:
- department must be one of: design, content, programming, photography
- Include every department that the package or request actually needs
- hours = estimated working hours until due (8 to 120)
- priority: 1 urgent, 2 high, 3 normal, 4 low
- brief must be actionable and in Arabic
- Do not invent departments that are not implied

{$packageBlock}
Title: {$title}
Description: {$description}
PROMPT;

        try {
            $response = $this->generateJson($prompt);
            if (! $response->successful()) {
                Log::warning('Gemini work plan failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return $this->heuristicPlan($title, $description, $packageContext);
            }

            $text = $this->extractJsonText(trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', '')));
            $decoded = json_decode($text, true);
            $operations = is_array($decoded) ? ($decoded['operations'] ?? $decoded) : null;
            if (! is_array($operations) || $operations === []) {
                return $this->heuristicPlan($title, $description, $packageContext);
            }

            return $this->normalizeOperations($operations) ?: $this->heuristicPlan($title, $description, $packageContext);
        } catch (\Throwable $exception) {
            Log::warning('Gemini work plan exception.', ['error' => $exception->getMessage()]);

            return $this->heuristicPlan($title, $description, $packageContext);
        }
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

    /**
     * @param  list<mixed>  $operations
     * @return list<array{department: string, brief: string, hours: int, priority: int}>
     */
    private function normalizeOperations(array $operations): array
    {
        $allowed = ['design', 'content', 'programming', 'photography'];
        $normalized = [];

        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }
            $department = strtolower(trim((string) ($operation['department'] ?? '')));
            $department = match ($department) {
                'web', 'dev', 'development', 'برمجة', 'ويب' => 'programming',
                'photo', 'media', 'تصوير', '3d' => 'photography',
                'تصميم', 'branding', 'print' => 'design',
                'محتوى' => 'content',
                default => $department,
            };
            if (! in_array($department, $allowed, true)) {
                continue;
            }
            $brief = trim((string) ($operation['brief'] ?? ''));
            if ($brief === '') {
                continue;
            }
            $hours = (int) ($operation['hours'] ?? 24);
            $priority = (int) ($operation['priority'] ?? 3);
            $normalized[] = [
                'department' => $department,
                'brief' => $brief,
                'hours' => max(8, min(120, $hours)),
                'priority' => max(1, min(4, $priority)),
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array{department: string, brief: string, hours: int, priority: int}>
     */
    private function heuristicPlan(string $title, string $description, ?string $packageContext): array
    {
        $haystack = mb_strtolower($title.' '.$description.' '.($packageContext ?? ''));
        $operations = [];

        $add = function (string $department, string $brief, int $hours, int $priority) use (&$operations): void {
            $operations[] = compact('department', 'brief', 'hours', 'priority');
        };

        if (str_contains($haystack, 'برمج') || str_contains($haystack, 'موقع') || str_contains($haystack, 'web') || str_contains($haystack, 'program')) {
            $add('programming', 'تنفيذ الجانب التقني للطلب: '.$title, 72, 2);
        }
        if (str_contains($haystack, 'تصوير') || str_contains($haystack, 'فيديو') || str_contains($haystack, 'photo') || str_contains($haystack, 'reel')) {
            $add('photography', 'تصوير أو إنتاج بصري للطلب: '.$title, 36, 2);
        }
        if (str_contains($haystack, 'محتوى') || str_contains($haystack, 'كتابة') || str_contains($haystack, 'content') || str_contains($haystack, 'copy')) {
            $add('content', 'إعداد المحتوى للطلب: '.$title, 24, 3);
        }
        if (str_contains($haystack, 'تصميم') || str_contains($haystack, 'شعار') || str_contains($haystack, 'هوي') || str_contains($haystack, 'design') || str_contains($haystack, 'brand')) {
            $add('design', 'تنفيذ التصميم للطلب: '.$title, 48, 2);
        }

        return $operations !== [] ? $operations : [[
            'department' => 'design',
            'brief' => 'تنفيذ العمل المطلوب: '.$title,
            'hours' => 48,
            'priority' => 3,
        ]];
    }

    /**
     * @param  list<string>  $pages
     * @param  list<string>  $memories
     * @return array{reply: string, pages: list<string>, remember: ?string}
     */
    public function editReport(string $instruction, array $pages, array $memories, ?int $pageIndex): array
    {
        $targets = $pageIndex === null ? $pages : [($pages[$pageIndex] ?? '<p></p>')];
        if (config('services.gemini.e2e_stub')) {
            $edited = array_map(
                fn (string $html): string => $html.'<p>مراجعة Gemini</p>',
                $targets,
            );

            return [
                'reply' => 'تمت المراجعة',
                'pages' => $edited,
                'remember' => null,
            ];
        }

        $this->requireApiKey();
        $memory = $memories === [] ? 'none' : implode("\n- ", $memories);
        $packet = [];
        foreach ($targets as $index => $html) {
            $packet[] = 'PAGE '.($index + 1).":\n".$this->reportPromptHtml($html);
        }
        $prompt = <<<PROMPT
You edit an Arabic RTL client report. Return ONLY JSON:
{"reply":"short Arabic note","pages":["<p>html</p>"],"remember":null}
pages must contain exactly the same number of items as the pages below.
Keep existing tags. Do not add scripts, iframes, or event handlers.
Use the saved memory when it still fits the instruction.
Memory:
- {$memory}
Instruction:
{$instruction}
Pages:
PROMPT;
        $response = $this->generateJson($prompt."\n".implode("\n\n", $packet), 60);
        if (! $response->successful()) {
            Log::warning('Gemini report edit failed', ['status' => $response->status()]);
            throw ValidationException::withMessages([
                'gemini' => $this->failedClassificationMessage((string) $response->json('error.message', 'Gemini report edit failed.')),
            ]);
        }
        $text = (string) $response->json('candidates.0.content.parts.0.text', '');
        $decoded = json_decode($this->extractJsonText($text), true);
        $edited = is_array($decoded) ? ($decoded['pages'] ?? null) : null;
        if (! is_array($edited) || count($edited) !== count($targets)) {
            throw ValidationException::withMessages(['gemini' => 'Gemini returned an unexpected page edit.']);
        }

        return [
            'reply' => is_string($decoded['reply'] ?? null) ? $decoded['reply'] : 'تم التعديل.',
            'pages' => array_map(fn ($html): string => is_string($html) ? $html : '<p></p>', $edited),
            'remember' => is_string($decoded['remember'] ?? null) && trim($decoded['remember']) !== '' ? trim($decoded['remember']) : null,
        ];
    }

    private function reportPromptHtml(string $html): string
    {
        $html = preg_replace('/src="data:[^"]*"/i', 'src=""', $html) ?? $html;

        return mb_substr($html, 0, 8000);
    }

    private function generateJson(string $prompt, ?int $timeout = null): Response
    {
        $model = (string) config('services.gemini.model', 'gemini-3.6-flash');

        return Http::timeout($timeout ?? (int) config('services.gemini.timeout', 30))
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
