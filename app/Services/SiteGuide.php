<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ContactChannel;
use App\Models\PortfolioProject;
use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SiteGuide
{
    public function brief(): string
    {
        $cached = Cache::get('site-guide-v3');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $published = $this->publishedBrief();
        $brief = trim($this->practice()."\n\n".$this->packageComparison()."\n\n".$published."\n\n".$this->liveCatalog());
        if ($brief === '') {
            $brief = 'Published site brief could not be loaded. Tell the visitor to open https://hoc.agency/ and do not invent facts.';
        }

        Cache::put('site-guide-v3', $brief, now()->addMinutes($published === '' ? 2 : 30));

        return $brief;
    }

    private function practice(): string
    {
        return <<<'TEXT'
Company practice. This is how Home of Creativity chooses work. Prices, package names, and features still come only from the package comparison below. If a package kind named here is missing from that comparison, do not invent it.

The agency builds a recognizable brand: visual identity, social content, account management, marketing, and managed paid ads. A subscription is ongoing work. A reach package is one payment for one push, and it is for someone who is not on a subscription. Work starts in the chat or on https://hoc.agency/. The agency sends a quotation, and payment is confirmed before work. There is no self-checkout.

"Best results" means the published work that matches the size of the business: a steady content rhythm, managed platforms, and a performance report. Do not promise sales, rankings, follower counts, or any result that is not written in the package comparison.

Choose exactly one published package:
- One shop, stall, or one-branch local business (a citrus shop, a bakery, a single clinic) that wants a subscription and better results: the package whose subtitle is منشآت صغيرة / small business. Explain why its listed features fit one shop, give its published monthly total, then one line on what the mid-size package adds if they grow.
- Several branches, a competitor problem, or a need for Google Maps and an advisory team: the package whose subtitle is منشآت متوسطة / mid-size business.
- A large company: the package whose subtitle is منشآت كبيرة / enterprise.
- Posts and reels only, without the strategic layer: a production content pack, not a strategic subscription.
- One seasonal advertisement, not a subscription: a one-time reach package, and say it is not a subscription.

Private information stays closed. Never mention or guess other clients, their results, invoices, quotations, phone numbers, staff names, salaries, internal costs, margins, unpublished discounts, Odoo, ClickUp, or another person's conversation. If the visitor asks for any of that, say it is not shared, then continue from the published packages.
TEXT;
    }

    private function publishedBrief(): string
    {
        $url = trim((string) config('services.site.guide_url', 'https://hoc.agency/llms-full.txt'));
        if ($url === '') {
            return '';
        }

        try {
            $response = Http::timeout(5)->connectTimeout(3)->accept('text/plain')->get($url);
        } catch (\Throwable $exception) {
            Log::warning('Site guide fetch failed.', ['error' => $exception->getMessage()]);

            return '';
        }

        if (! $response->successful()) {
            return '';
        }

        return mb_substr(trim($response->body()), 0, 10000);
    }

    private function liveCatalog(): string
    {
        $lines = ['Live catalog from the dashboard. Prefer these phones, projects, and articles when they differ from the brief above. For package prices and features, use the comparison block.'];

        try {
            ContactChannel::query()
                ->where('is_published', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(30)
                ->get(['kind', 'region', 'value', 'url'])
                ->each(function (ContactChannel $channel) use (&$lines): void {
                    $value = trim((string) ($channel->value ?: $channel->url));
                    if ($value !== '') {
                        $lines[] = '- Contact '.$channel->kind.' '.$channel->region.': '.$value;
                    }
                });

            PortfolioProject::query()
                ->where('is_published', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(40)
                ->get(['id', 'title_ar', 'title_en'])
                ->each(function (PortfolioProject $project) use (&$lines): void {
                    $title = trim($project->title_ar.' / '.$project->title_en, ' /');
                    if ($title !== '') {
                        $lines[] = '- Project '.$project->id.': '.$title.' — https://hoc.agency/projects/'.$project->id.'/';
                    }
                });

            Article::query()
                ->published()
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get(['slug', 'title_ar', 'title_en'])
                ->each(function (Article $article) use (&$lines): void {
                    $title = trim($article->title_ar.' / '.$article->title_en, ' /');
                    if ($title !== '' && $article->slug !== '') {
                        $lines[] = '- Article: '.$title.' — https://hoc.agency/articles/'.$article->slug.'/';
                    }
                });
        } catch (\Throwable $exception) {
            Log::warning('Site guide catalog failed.', ['error' => $exception->getMessage()]);
        }

        return mb_substr(implode("\n", $lines), 0, 4000);
    }

    private function packageComparison(): string
    {
        $lines = [
            'Package comparison. These prices are the published totals (monthly, 3 months, 6 months, yearly). Do not add another discount on top. Compare only features written here. A feature missing from a package is not included.',
        ];

        try {
            $packages = PricingPackage::query()
                ->where('is_published', true)
                ->whereHas('subcategory', function ($query): void {
                    $query->where('is_published', true)
                        ->whereHas('category', fn ($category) => $category->where('is_published', true));
                })
                ->with(['subcategory.category'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(40)
                ->get();

            $grouped = [];
            foreach ($packages as $package) {
                $subcategory = $package->subcategory;
                $category = $subcategory?->category;
                if (! $subcategory instanceof PricingSubcategory || ! $category instanceof PricingCategory) {
                    continue;
                }
                $group = trim($category->name_ar.' / '.$category->name_en, ' /')
                    .' — '.trim($subcategory->name_ar.' / '.$subcategory->name_en, ' /');
                $grouped[$group][] = $this->packageBlock($package);
            }

            foreach ($grouped as $group => $blocks) {
                $lines[] = '['.$group.']';
                foreach ($blocks as $block) {
                    $lines[] = $block;
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Site guide packages failed.', ['error' => $exception->getMessage()]);
        }

        return mb_substr(implode("\n", $lines), 0, 14000);
    }

    private function packageBlock(PricingPackage $package): string
    {
        $name = trim($package->name_ar.' / '.$package->name_en, ' /');
        $subtitle = trim((string) $package->subtitle_ar.' / '.(string) $package->subtitle_en, ' /');
        $features = [];
        foreach (is_array($package->features) ? $package->features : [] as $feature) {
            $line = $this->featureLine($feature);
            if ($line !== '') {
                $features[] = $line;
            }
        }

        $block = '- '.$name;
        if ($subtitle !== '') {
            $block .= ' ('.$subtitle.')';
        }
        $block .= ' — '.$this->priceLabel($package);
        $block .= "\n  features: ".($features === [] ? 'none listed' : implode(' | ', $features));

        $reach = is_array($package->reach) ? $package->reach : [];
        $reachBits = [];
        if (isset($reach['adBudgetUsd'])) {
            $reachBits[] = 'ad budget $'.$reach['adBudgetUsd'];
        }
        if (isset($reach['adCreditUsd'])) {
            $reachBits[] = 'ad credit $'.$reach['adCreditUsd'];
        }
        $audience = $this->featureLine($reach['estimatedReach'] ?? null);
        if ($audience !== '') {
            $reachBits[] = 'estimated reach '.$audience;
        }
        $goal = $this->featureLine($reach['goal'] ?? null);
        if ($goal !== '') {
            $reachBits[] = 'goal '.$goal;
        }
        if ($reachBits !== []) {
            $block .= "\n  reach: ".implode(' | ', $reachBits);
        }

        return $block;
    }

    private function priceLabel(PricingPackage $package): string
    {
        $prices = is_array($package->prices) ? $package->prices : [];
        $parts = [];
        foreach ([
            'monthly' => 'monthly',
            'quarterly' => '3 months',
            'semiannual' => '6 months',
            'yearly' => 'yearly',
        ] as $key => $label) {
            if (isset($prices[$key]) && is_numeric($prices[$key])) {
                $parts[] = $label.' $'.$prices[$key];
            }
        }
        if ($parts !== []) {
            return implode(', ', $parts);
        }
        if ($package->price_usd !== null) {
            return 'one-time $'.$package->price_usd;
        }

        return 'price not published';
    }

    private function featureLine(mixed $feature): string
    {
        if (is_string($feature)) {
            return trim($feature);
        }
        if (! is_array($feature)) {
            return '';
        }
        $ar = trim((string) ($feature['ar'] ?? ''));
        $en = trim((string) ($feature['en'] ?? ''));
        if ($ar !== '' && $en !== '') {
            return $ar.' / '.$en;
        }

        return $ar !== '' ? $ar : $en;
    }
}
