<?php

namespace App\Console\Commands;

use App\Services\GoogleServiceAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SubmitSearchSitemapCommand extends Command
{
    protected $signature = 'seo:submit-sitemap {--url= : Full sitemap URL}';

    protected $description = 'Ping Google and submit the marketing sitemap to Search Console.';

    public function handle(GoogleServiceAccount $google): int
    {
        $sitemap = (string) ($this->option('url') ?: config('services.google.search_sitemap_url') ?: 'https://hoc.agency/sitemap.xml');
        $sitemap = rtrim($sitemap);
        if ($sitemap === '') {
            $this->warn('No sitemap URL configured.');

            return self::SUCCESS;
        }

        $ping = Http::timeout(12)
            ->connectTimeout(5)
            ->get('https://www.google.com/ping', ['sitemap' => $sitemap]);

        if ($ping->successful()) {
            $this->info('Google sitemap ping accepted.');
        } else {
            $this->warn('Google sitemap ping returned HTTP '.$ping->status().'.');
        }

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
}
