<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubmitSearchSitemapCommandTest extends TestCase
{
    public function test_indexnow_succeeds_without_search_console_credentials(): void
    {
        Http::fake([
            'https://api.indexnow.org/*' => Http::response('ok', 202),
            'https://oauth2.googleapis.com/*' => Http::response(['error' => 'unauthorized'], 401),
            'https://www.googleapis.com/webmasters/*' => Http::response(['error' => 'forbidden'], 403),
        ]);

        $this->artisan('seo:submit-sitemap', ['--url' => 'https://hoc.agency/sitemap.xml'])
            ->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === 'https://api.indexnow.org/indexnow'
            && $request['host'] === 'hoc.agency'
            && $request['urlList'][0] === 'https://hoc.agency/'
            && in_array('https://hoc.agency/social/', $request['urlList'], true)
            && in_array('https://hoc.agency/locations/', $request['urlList'], true)
            && in_array('https://hoc.agency/privacy/', $request['urlList'], true)
            && in_array('https://hoc.agency/terms/', $request['urlList'], true)
            && in_array('https://hoc.agency/llms.txt', $request['urlList'], true)
            && in_array('https://hoc.agency/llms-full.txt', $request['urlList'], true));
    }
}
