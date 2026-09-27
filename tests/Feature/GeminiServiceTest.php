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
            'services.gemini.vertex_project' => '',
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
            return str_contains($request->url(), '/models/gemini-3.6-flash:generateContent')
                && ! str_contains($request->url(), 'key=')
                && $request->hasHeader('x-goog-api-key', 'studio-auth-key');
        });
    }

    public function test_english_briefs_are_translated_to_arabic(): void
    {
        config([
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => 'studio-auth-key',
            'services.gemini.vertex_project' => '',
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
            'services.gemini.vertex_project' => '',
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

    public function test_vertex_project_uses_gcloud_bearer_on_the_global_endpoint(): void
    {
        config([
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => '',
            'services.gemini.vertex_project' => 'project-9c32a8a1-2afa-499c-882',
            'services.gemini.vertex_location' => 'global',
            'services.gemini.vertex_access_token' => 'test-access-token',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://aiplatform.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => 'thinking'],
                            ['text' => '{"work_type":"content","briefs":[{"type":"content","brief":"اكتب النص"}]}'],
                        ],
                    ],
                ]],
            ], 200),
        ]);

        $result = app(GeminiService::class)->classify('Post', 'Write a caption');

        $this->assertSame(WorkType::Content, $result['work_type']);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/projects/project-9c32a8a1-2afa-499c-882/locations/global/publishers/google/models/gemini-3.6-flash:generateContent')
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && ! $request->hasHeader('x-goog-api-key');
        });
    }

    public function test_report_image_uses_the_vertex_image_model(): void
    {
        config([
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => '',
            'services.gemini.vertex_project' => 'project-9c32a8a1-2afa-499c-882',
            'services.gemini.vertex_location' => 'global',
            'services.gemini.vertex_access_token' => 'test-access-token',
            'services.gemini.image_model' => 'gemini-2.5-flash-image',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://aiplatform.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'inlineData' => [
                                'mimeType' => 'image/png',
                                'data' => base64_encode('png-bytes'),
                            ],
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $image = app(GeminiService::class)->generateReportImage('دائرة برتقالية');

        $this->assertSame('image/png', $image['mime']);
        $this->assertSame('png-bytes', $image['bytes']);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/models/gemini-2.5-flash-image:generateContent')
                && $request->hasHeader('Authorization', 'Bearer test-access-token');
        });
    }

    public function test_report_image_sends_the_source_picture(): void
    {
        config([
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => '',
            'services.gemini.vertex_project' => 'project-9c32a8a1-2afa-499c-882',
            'services.gemini.vertex_location' => 'global',
            'services.gemini.vertex_access_token' => 'test-access-token',
            'services.gemini.image_model' => 'gemini-2.5-flash-image',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://aiplatform.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'inlineData' => [
                                'mimeType' => 'image/png',
                                'data' => base64_encode('out'),
                            ],
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $image = app(GeminiService::class)->generateReportImage('ارفع الجودة', [
            'mime' => 'image/jpeg',
            'base64' => base64_encode('source'),
        ]);

        $this->assertSame('out', $image['bytes']);
        Http::assertSent(function ($request): bool {
            $body = $request->body();

            return str_contains($body, '"inlineData"')
                && str_contains($body, 'image\\/jpeg')
                && str_contains($body, base64_encode('source'));
        });
    }

    public function test_report_edit_sends_the_attached_image(): void
    {
        config([
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => '',
            'services.gemini.vertex_project' => 'project-9c32a8a1-2afa-499c-882',
            'services.gemini.vertex_location' => 'global',
            'services.gemini.vertex_access_token' => 'test-access-token',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://aiplatform.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"reply":"رأيت الصورة","pages":["<p>نص</p>"],"remember":null}',
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $result = app(GeminiService::class)->editReport(
            'صف الصورة',
            ['<p>نص</p>'],
            [],
            null,
            ['mime' => 'image/jpeg', 'base64' => base64_encode('jpeg-bytes')],
        );

        $this->assertSame('رأيت الصورة', $result['reply']);
        Http::assertSent(function ($request): bool {
            $body = $request->body();

            return str_contains($request->url(), '/models/gemini-3.6-flash:generateContent')
                && str_contains($body, '"inlineData"')
                && str_contains($body, 'image\\/jpeg')
                && str_contains($body, base64_encode('jpeg-bytes'));
        });
    }
}
