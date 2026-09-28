<?php

namespace App\Http\Controllers;

use App\Http\Resources\LegalPageResource;
use App\Models\LegalPage;
use App\Support\LegalDefaults;

class LegalPageController extends Controller
{
    public function index()
    {
        $stored = LegalPage::query()->get()->keyBy('slug');

        $pages = collect(LegalDefaults::pages())->map(function (array $defaults) use ($stored) {
            $page = $stored->get($defaults['slug']);

            if ($page instanceof LegalPage && ! $page->isPlaceholderOnly()) {
                return $page;
            }

            return new LegalPage($defaults);
        });

        return LegalPageResource::collection($pages)->additional(['message' => 'ok']);
    }

    public function show(string $slug)
    {
        abort_unless(in_array($slug, LegalPage::SLUGS, true), 404);

        $page = LegalPage::query()->where('slug', $slug)->first();

        if (! $page instanceof LegalPage || $page->isPlaceholderOnly()) {
            $defaults = collect(LegalDefaults::pages())->firstWhere('slug', $slug);
            $page = new LegalPage($defaults);
        }

        return LegalPageResource::make($page)
            ->additional(['message' => 'ok']);
    }
}
