<?php

namespace Tests\Feature;

use App\Enums\WorkType;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    public function test_classify_sends_auth_header_without_query_key(): void
    {
        config([
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => 'studio-auth-key',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"work_type":"design","briefs":[{"type":"design","brief":"موجز"}]}',
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $result = app(GeminiService::class)->classify('Logo', 'Brand identity');

        $this->assertSame(WorkType::Design, $result['work_type']);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/models/gemini-2.5-flash:generateContent')
                && ! str_contains($request->url(), 'key=')
                && $request->hasHeader('x-goog-api-key', 'studio-auth-key');
        });
    }

    public function test_english_briefs_are_translated_to_arabic(): void
    {
        config([
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => 'studio-auth-key',
            'services.google_translate.enabled' => true,
            'services.google_translate.api_key' => '',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"work_type":"design","briefs":[{"type":"design","brief":"Design the booth signage."}]}',
                        ]],
                    ],
                ]],
            ], 200),
            'https://translate.googleapis.com/*' => Http::response([
                [['صمم لافتة الجناح.', 'Design the booth signage.', null, null, 10]],
            ], 200),
        ]);

        $result = app(GeminiService::class)->classify('Logo', 'Brand identity');

        $this->assertSame('صمم لافتة الجناح.', $result['briefs'][0]['brief']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'translate.googleapis.com'));
    }

    public function test_blocked_standard_key_explains_auth_key_requirement(): void
    {
        config([
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => 'legacy-key',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'Requests to this API generativelanguage.googleapis.com method google.ai.generativelanguage.v1beta.GenerativeService.GenerateContent are blocked.',
                    'status' => 'PERMISSION_DENIED',
                ],
            ], 403),
        ]);

        try {
            app(GeminiService::class)->classify('Logo', 'Brand identity');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['gemini'][0] ?? '';
            $this->assertStringContainsString('GEMINI_API_KEY', $message);
            $this->assertStringContainsString('AI Studio', $message);
        }
    }
}
