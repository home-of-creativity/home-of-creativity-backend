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

    public function show(LegalPage $legalPage)
    {
        return LegalPageResource::make($legalPage)
            ->additional(['message' => 'ok']);
    }

    public function update(UpdateLegalPageRequest $request, LegalPage $legalPage): LegalPageResource
    {
        $data = $request->validated();
        $legalPage->fill([
            'title_ar' => $data['title_ar'],
            'title_en' => $data['title_en'],
            'sections' => LegalHtml::cleanSections($data['sections']),
        ])->save();

        return LegalPageResource::make($legalPage->fresh())
            ->additional(['message' => 'Updated.']);
    }
}
