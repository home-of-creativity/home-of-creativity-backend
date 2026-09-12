<?php

namespace App\Http\Controllers;

use App\Http\Resources\PortfolioCategoryResource;
use App\Http\Resources\PortfolioProjectResource;
use App\Http\Resources\ShowcaseClientResource;
use App\Models\PortfolioCategory;
use App\Models\PortfolioProject;
use App\Models\ShowcaseClient;

class PortfolioController extends Controller
{
    public function clients()
    {
        return ShowcaseClientResource::collection(
            ShowcaseClient::query()
                ->where('is_published', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        )->additional(['message' => 'ok']);
    }

    public function projects()
    {
        $categories = PortfolioCategory::query()
            ->where('is_published', true)
            ->with(['projects' => fn ($query) => $query
                ->where('is_published', true)
                ->with('images')
                ->orderBy('sort_order')
                ->orderByDesc('id')])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (PortfolioCategory $category) => $category->projects->isNotEmpty());

        return response()->json([
            'data' => [
                'categories' => PortfolioCategoryResource::collection($categories),
                'projects' => PortfolioProjectResource::collection(
                    PortfolioProject::query()
                        ->with(['category', 'images'])
                        ->where('is_published', true)
                        ->orderBy('sort_order')
                        ->orderByDesc('id')
                        ->get()
                ),
            ],
            'message' => 'ok',
        ]);
    }

    public function show(PortfolioProject $portfolioProject)
    {
        if (! $portfolioProject->is_published) {
            abort(404);
        }

        $portfolioProject->load(['category', 'images']);

        return PortfolioProjectResource::make($portfolioProject)
            ->additional(['message' => 'ok']);
    }
}
