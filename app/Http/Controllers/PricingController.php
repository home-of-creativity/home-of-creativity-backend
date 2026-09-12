<?php

namespace App\Http\Controllers;

use App\Http\Resources\PricingCategoryResource;
use App\Models\PricingCategory;
use Illuminate\Http\Request;

class PricingController extends Controller
{
    public function index(Request $request)
    {
        $categories = PricingCategory::query()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->with([
                'subcategories' => fn ($q) => $q
                    ->where('is_published', true)
                    ->orderBy('sort_order')
                    ->orderBy('name_en')
                    ->with([
                        'packages' => fn ($q) => $q
                            ->where('is_published', true)
                            ->orderBy('sort_order')
                            ->orderBy('name_en'),
                    ]),
            ])
            ->get()
            ->filter(fn (PricingCategory $category) => $category->subcategories->isNotEmpty());

        return PricingCategoryResource::collection($categories)
            ->additional(['message' => 'ok']);
    }
}
