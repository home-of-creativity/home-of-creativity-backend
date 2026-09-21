<?php

namespace App\Http\Controllers;

use App\Http\Resources\LegalPageResource;
use App\Models\LegalPage;

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
}
