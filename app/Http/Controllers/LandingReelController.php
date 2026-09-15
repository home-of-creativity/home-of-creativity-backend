<?php

namespace App\Http\Controllers;

use App\Http\Resources\LandingReelResource;
use App\Models\LandingReel;

class LandingReelController extends Controller
{
    public function index()
    {
        return LandingReelResource::collection(
            LandingReel::query()
                ->published()
                ->orderBy('sort_order')
                ->orderByDesc('id')
                ->get()
        )->additional(['message' => 'ok']);
    }
}
