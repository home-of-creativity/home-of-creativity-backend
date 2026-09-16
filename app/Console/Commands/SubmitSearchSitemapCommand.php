<?php

namespace App\Console\Commands;

use App\Services\GoogleServiceAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SubmitSearchSitemapCommand extends Command
{
    protected $signature = 'seo:submit-sitemap {--url= : Full sitemap URL}';

    protected $description = 'Notify IndexNow and submit the marketing sitemap to Search Console.';

    public function handle(GoogleServiceAccount $google): int
    {
        $sitemap = (string) ($this->option('url') ?: config('services.google.search_sitemap_url') ?: 'https://hoc.agency/sitemap.xml');
        $sitemap = rtrim($sitemap);
        if ($sitemap === '') {
            $this->warn('No sitemap URL configured.');

            return self::SUCCESS;
        }

        $this->notifyIndexNow($sitemap);

        $token = $google->searchConsoleToken();
        if ($token === null) {
            $this->comment('Search Console API skipped (service account not configured or webmasters scope missing).');

            return self::SUCCESS;
        }

        $site = rtrim((string) config('services.google.search_site_url'), '/').'/';
        $encodedSite = rawurlencode($site);
        $encodedFeed = rawurlencode($sitemap);
        $endpoint = "https://www.googleapis.com/webmasters/v3/sites/{$encodedSite}/sitemaps/{$encodedFeed}";

        $submit = Http::withToken($token)
            ->timeout(15)
            ->connectTimeout(5)
            ->put($endpoint);

        if ($submit->successful()) {
            $this->info('Search Console sitemap submitted.');

            return self::SUCCESS;
        }

        $this->warn('Search Console API HTTP '.$submit->status().': '.$submit->body());

        return self::SUCCESS;
    }

    private function notifyIndexNow(string $sitemap): void
    {
        $key = (string) config('services.google.indexnow_key');
        if ($key === '') {
            $this->comment('IndexNow skipped (INDEXNOW_KEY empty).');

            return;
        }

        $host = parse_url((string) config('services.google.search_site_url', 'https://hoc.agency/'), PHP_URL_HOST) ?: 'hoc.agency';
        $origin = 'https://'.$host;
        $payload = [
            'host' => $host,
            'key' => $key,
            'keyLocation' => $origin.'/'.$key.'.txt',
            'urlList' => [
                $origin.'/',
                $origin.'/pricing/',
                $sitemap,
            ],
        ];

        $ping = Http::timeout(12)
            ->connectTimeout(5)
            ->acceptJson()
            ->asJson()
            ->post('https://api.indexnow.org/indexnow', $payload);

        if ($ping->successful() || $ping->status() === 202) {
            $this->info('IndexNow accepted.');

            return;
        }

        $this->warn('IndexNow returned HTTP '.$ping->status().'.');
    }
}
