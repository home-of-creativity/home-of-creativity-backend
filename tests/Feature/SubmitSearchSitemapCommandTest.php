<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubmitSearchSitemapCommandTest extends TestCase
{
    public function test_ping_succeeds_without_search_console_credentials(): void
    {
        Http::fake([
            'https://www.google.com/ping*' => Http::response('ok', 200),
            'https://oauth2.googleapis.com/*' => Http::response(['error' => 'unauthorized'], 401),
            'https://www.googleapis.com/webmasters/*' => Http::response(['error' => 'forbidden'], 403),
        ]);

        $this->artisan('seo:submit-sitemap', ['--url' => 'https://hoc.agency/sitemap.xml'])
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'https://www.google.com/ping')
            && $request['sitemap'] === 'https://hoc.agency/sitemap.xml');
    }
}
