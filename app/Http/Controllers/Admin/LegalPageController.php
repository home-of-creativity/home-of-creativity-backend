<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateLegalPageRequest;
use App\Http\Resources\LegalPageResource;
use App\Models\LegalPage;
use App\Support\LegalHtml;

class LegalPageController extends Controller
{
    public function index()
    {
        return LegalPageResource::collection(
            LegalPage::query()->orderBy('slug')->get()
        )->additional(['message' => 'ok']);
    }

    public function show(string $slug)
    {
        abort_unless(in_array($slug, LegalPage::SLUGS, true), 404);

        $page = LegalPage::query()->where('slug', $slug)->first()
            ?? new LegalPage(LegalPage::scaffold($slug));

        return LegalPageResource::make($page)
            ->additional(['message' => 'ok']);
    }

    public function update(UpdateLegalPageRequest $request, string $slug): LegalPageResource
    {
        abort_unless(in_array($slug, LegalPage::SLUGS, true), 404);

        $data = $request->validated();
        $page = LegalPage::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'title_ar' => $data['title_ar'],
                'title_en' => $data['title_en'],
                'sections' => LegalHtml::cleanSections($data['sections']),
            ],
        );

        return LegalPageResource::make($page->fresh())
            ->additional(['message' => 'Updated.']);
    }
}
