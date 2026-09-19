<?php

namespace Tests\Feature;

use App\Services\GoogleTranslateService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleTranslateServiceTest extends TestCase
{
    public function test_arabic_text_is_left_unchanged(): void
    {
        config(['services.google_translate.enabled' => true]);
        Http::preventStrayRequests();

        $this->assertSame(
            'صمم الهوية البصرية',
            app(GoogleTranslateService::class)->toArabic('صمم الهوية البصرية'),
        );
    }

    public function test_english_uses_google_translate_when_cloud_key_is_missing(): void
    {
        config([
            'services.google_translate.enabled' => true,
            'services.google_translate.api_key' => '',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://translate.googleapis.com/*' => Http::response([
                [['موجز التصميم', 'Design brief', null, null, 10]],
            ], 200),
        ]);

        $this->assertSame(
            'موجز التصميم',
            app(GoogleTranslateService::class)->toArabic('Design brief'),
        );
    }

    public function test_disabled_translate_returns_original(): void
    {
        config(['services.google_translate.enabled' => false]);
        Http::preventStrayRequests();

        $this->assertSame(
            'Design brief',
            app(GoogleTranslateService::class)->toArabic('Design brief'),
        );
    }
}
