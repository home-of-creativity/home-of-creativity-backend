<?php

namespace App\Services;

use App\Enums\WorkType;
use App\Models\DepartmentBrief;
use App\Models\ServiceRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class GeminiService
{
    private static ?string $vertexToken = null;

    private static int $vertexTokenExpires = 0;

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

        $text = $this->responseText($response);
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

            $text = $this->extractJsonText($this->responseText($response));
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

            $text = $this->responseText($response);
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
     * @param  array{mime: string, base64: string}|null  $image
     * @return array{reply: string, pages: list<string>, remember: ?string}
     */
    public function editReport(string $instruction, array $pages, array $memories, ?int $pageIndex, ?array $image = null): array
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
        if ($image !== null) {
            $decoded = base64_decode($image['base64'], true);
            if ($decoded === false || $decoded === '' || strlen($decoded) > 4_000_000) {
                throw ValidationException::withMessages(['gemini' => 'The attached image is too large.']);
            }
        }
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
Preserve every line break and every list number.
A blank line must stay an empty <p></p>. Do not merge lines into one paragraph.
A numbered list must stay <ol><li> items in the same order and count. Do not drop or renumber items unless the instruction asks.
Use the saved memory when it still fits the instruction.
Memory:
- {$memory}
Instruction:
{$instruction}
PROMPT;
        if ($image !== null) {
            $prompt .= "\nAn image is attached. Use what it shows when the instruction refers to a picture, layout, color, or wording in that image. Do not describe anything the image does not show.\n";
        }
        $prompt .= "Pages:\n";
        $response = $this->generateJson($prompt.implode("\n\n", $packet), 60, $image);
        if (! $response->successful()) {
            Log::warning('Gemini report edit failed', ['status' => $response->status()]);
            throw ValidationException::withMessages([
                'gemini' => $this->failedClassificationMessage((string) $response->json('error.message', 'Gemini report edit failed.')),
            ]);
        }
        $text = $this->responseText($response);
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

    /**
     * @param  array{mime: string, base64: string}|null  $source
     * @return array{mime: string, bytes: string}
     */
    public function generateReportImage(string $prompt, ?array $source = null): array
    {
        if (config('services.gemini.e2e_stub')) {
            return [
                'mime' => 'image/png',
                'bytes' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
            ];
        }

        $this->requireApiKey();
        if ($source !== null) {
            $decoded = base64_decode($source['base64'], true);
            if ($decoded === false || $decoded === '' || strlen($decoded) > 4_000_000) {
                throw ValidationException::withMessages(['gemini' => 'The attached image is too large.']);
            }
            $prompt .= "\nThe attached image is the source. Follow the instruction on that image. Keep the same subject unless the instruction asks for a new one.";
        }
        $parts = [['text' => $prompt]];
        if ($source !== null) {
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $source['mime'],
                    'data' => $source['base64'],
                ],
            ];
        }
        $model = (string) config('services.gemini.image_model', 'gemini-2.5-flash-image');
        $request = Http::timeout(60)->connectTimeout(5)->acceptJson();
        $request = $this->usesVertex()
            ? $request->withToken($this->vertexAccessToken())
            : $request->withHeaders(['x-goog-api-key' => $this->apiKey()]);
        $response = $request->post($this->generateContentUrl($model), [
            'contents' => [
                ['role' => 'user', 'parts' => $parts],
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
            ],
        ]);
        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'gemini' => $this->failedClassificationMessage((string) $response->json('error.message', 'Gemini image generation failed.')),
            ]);
        }

        $parts = $response->json('candidates.0.content.parts');
        if (! is_array($parts)) {
            throw ValidationException::withMessages(['gemini' => 'Gemini returned no image.']);
        }
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (! is_array($inline) || ! is_string($inline['data'] ?? null)) {
                continue;
            }
            $bytes = base64_decode($inline['data'], true);
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $mime = $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png';

            return [
                'mime' => is_string($mime) && $mime !== '' ? $mime : 'image/png',
                'bytes' => $bytes,
            ];
        }

        throw ValidationException::withMessages(['gemini' => 'Gemini returned no image.']);
    }

    private function reportPromptHtml(string $html): string
    {
        $html = preg_replace('/src="data:[^"]*"/i', 'src=""', $html) ?? $html;

        return mb_substr($html, 0, 8000);
    }

    /**
     * @param  array{mime: string, base64: string}|null  $image
     */
    private function generateJson(string $prompt, ?int $timeout = null, ?array $image = null): Response
    {
        $model = (string) config('services.gemini.model', 'gemini-3.6-flash');
        $generation = [
            'temperature' => 0.2,
            'responseMimeType' => 'application/json',
        ];
        $request = Http::timeout($timeout ?? (int) config('services.gemini.timeout', 30))
            ->connectTimeout(5)
            ->acceptJson();

        if ($this->usesVertex()) {
            $generation['thinkingConfig'] = ['thinkingLevel' => 'LOW'];
            $request = $request->withToken($this->vertexAccessToken());
        } else {
            $request = $request->withHeaders(['x-goog-api-key' => $this->apiKey()]);
        }

        $parts = [['text' => $prompt]];
        if ($image !== null) {
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $image['mime'],
                    'data' => $image['base64'],
                ],
            ];
        }

        return $request->post($this->generateContentUrl($model), [
            'contents' => [
                ['role' => 'user', 'parts' => $parts],
            ],
            'generationConfig' => $generation,
        ]);
    }

    private function generateContentUrl(string $model): string
    {
        if ($this->usesVertex()) {
            $project = rawurlencode($this->vertexProject());
            $location = (string) config('services.gemini.vertex_location', 'global');
            $model = rawurlencode($model);
            if ($location === 'global') {
                return "https://aiplatform.googleapis.com/v1/projects/{$project}/locations/global/publishers/google/models/{$model}:generateContent";
            }
            $location = rawurlencode($location);

            return "https://{$location}-aiplatform.googleapis.com/v1/projects/{$project}/locations/{$location}/publishers/google/models/{$model}:generateContent";
        }

        $base = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');

        return "{$base}/models/{$model}:generateContent";
    }

    private function usesVertex(): bool
    {
        return $this->vertexProject() !== '';
    }

    private function vertexProject(): string
    {
        return trim((string) config('services.gemini.vertex_project'));
    }

    private function vertexAccessToken(): string
    {
        $configured = trim((string) config('services.gemini.vertex_access_token', ''));
        if ($configured !== '') {
            return $configured;
        }

        if (self::$vertexToken !== null && self::$vertexTokenExpires > time() + 60) {
            return self::$vertexToken;
        }

        $token = $this->tokenFromCredentialsFile() ?? $this->tokenFromGcloud();
        if ($token === null) {
            throw ValidationException::withMessages([
                'gemini' => 'Gemini classification failed: the API process has no saved Google Cloud credential. A browser login is not required on each request.',
            ]);
        }

        self::$vertexToken = $token;
        self::$vertexTokenExpires = time() + 2400;

        return $token;
    }

    private function tokenFromCredentialsFile(): ?string
    {
        $path = $this->credentialsPath();
        if ($path === null) {
            return null;
        }

        try {
            $json = json_decode((string) file_get_contents($path), true);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($json)) {
            return null;
        }

        return match ($json['type'] ?? '') {
            'authorized_user' => $this->refreshAuthorizedUser($json),
            'service_account' => $this->tokenFromServiceAccount($json),
            default => null,
        };
    }

    private function credentialsPath(): ?string
    {
        $configured = trim((string) config('services.gemini.vertex_credentials'));
        if ($configured !== '' && is_readable($configured)) {
            return $configured;
        }

        foreach ([
            storage_path('app/private/gemini-sa.json'),
            storage_path('app/private/google-sa.json'),
            base_path('storage/app/private/google-sa.json'),
        ] as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        $appData = getenv('APPDATA');
        if (is_string($appData) && $appData !== '') {
            $adc = $appData.DIRECTORY_SEPARATOR.'gcloud'.DIRECTORY_SEPARATOR.'application_default_credentials.json';
            if (is_readable($adc)) {
                return $adc;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function refreshAuthorizedUser(array $json): ?string
    {
        $clientId = $json['client_id'] ?? null;
        $clientSecret = $json['client_secret'] ?? null;
        $refresh = $json['refresh_token'] ?? null;
        if (! is_string($clientId) || ! is_string($clientSecret) || ! is_string($refresh)) {
            return null;
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refresh,
                'grant_type' => 'refresh_token',
            ]);
        $token = $response->json('access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function tokenFromServiceAccount(array $json): ?string
    {
        $email = $json['client_email'] ?? null;
        $privateKey = $json['private_key'] ?? null;
        if (! is_string($email) || ! is_string($privateKey) || $privateKey === '') {
            return null;
        }

        $now = time();
        $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = $this->base64Url((string) json_encode([
            'iss' => $email,
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $unsigned = $header.'.'.$claim;
        $signature = '';
        if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.$this->base64Url($signature),
            ]);
        $token = $response->json('access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function tokenFromGcloud(): ?string
    {
        $candidates = PHP_OS_FAMILY === 'Windows'
            ? array_filter([
                getenv('LOCALAPPDATA').'\\Google\\Cloud SDK\\google-cloud-sdk\\bin\\gcloud.cmd',
                'C:\\Program Files (x86)\\Google\\Cloud SDK\\google-cloud-sdk\\bin\\gcloud.cmd',
                'gcloud.cmd',
            ])
            : ['gcloud'];

        foreach ($candidates as $binary) {
            if ($binary !== 'gcloud.cmd' && $binary !== 'gcloud' && ! is_file($binary)) {
                continue;
            }
            $process = new Process([$binary, 'auth', 'print-access-token']);
            $process->setTimeout(20);
            $process->run();
            $token = trim($process->getOutput());
            if ($process->isSuccessful() && $token !== '') {
                return $token;
            }
        }

        return null;
    }

    private function responseText(Response $response): string
    {
        $parts = data_get($response->json(), 'candidates.0.content.parts', []);
        if (! is_array($parts)) {
            return '';
        }
        $texts = [];
        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null) && trim($part['text']) !== '') {
                $texts[] = trim($part['text']);
            }
        }

        return $texts === [] ? '' : $texts[array_key_last($texts)];
    }

    private function apiKey(): string
    {
        return trim((string) config('services.gemini.api_key'));
    }

    private function requireApiKey(): void
    {
        if ($this->usesVertex() || $this->apiKey() !== '') {
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
