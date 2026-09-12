<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePortfolioCategoryRequest;
use App\Http\Requests\UpdatePortfolioCategoryRequest;
use App\Http\Resources\PortfolioCategoryResource;
use App\Models\PortfolioCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioCategoryController extends Controller
{
    public function index()
    {
        return PortfolioCategoryResource::collection(
            PortfolioCategory::query()
                ->withCount('projects')
                ->orderBy('sort_order')
                ->orderBy('name_en')
                ->get()
        )->additional(['message' => 'ok']);
    }

    public function store(StorePortfolioCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['sort_order'] = $data['sort_order'] ?? ((int) PortfolioCategory::query()->max('sort_order')) + 1;
        $data['is_published'] = $data['is_published'] ?? true;

        $category = PortfolioCategory::query()->create($data);

        return PortfolioCategoryResource::make($category)
            ->additional(['message' => 'Created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdatePortfolioCategoryRequest $request, PortfolioCategory $portfolioCategory): PortfolioCategoryResource
    {
        $portfolioCategory->fill($request->validated())->save();

        return PortfolioCategoryResource::make($portfolioCategory->fresh())
            ->additional(['message' => 'Updated.']);
    }

    public function destroy(PortfolioCategory $portfolioCategory)
    {
        $portfolioCategory->delete();

        return response()->json([
            'data' => null,
            'message' => 'Deleted.',
        ]);
    }

    public function destroyAll()
    {
        PortfolioCategory::query()->delete();

        return response()->json([
            'data' => ['deleted' => true],
            'message' => 'All categories deleted.',
        ]);
    }

    public function move(Request $request, PortfolioCategory $portfolioCategory)
    {
        $direction = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ])['direction'];

        $categories = PortfolioCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get();

        $index = $categories->search(fn (PortfolioCategory $category) => $category->id === $portfolioCategory->id);
        if ($index === false) {
            abort(404);
        }

        $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swapIndex >= 0 && $swapIndex < $categories->count()) {
            $current = $categories[$index];
            $neighbor = $categories[$swapIndex];
            $currentOrder = $current->sort_order;
            $current->forceFill(['sort_order' => $neighbor->sort_order])->save();
            $neighbor->forceFill(['sort_order' => $currentOrder])->save();
        }

        return PortfolioCategoryResource::collection(
            PortfolioCategory::query()
                ->withCount('projects')
                ->orderBy('sort_order')
                ->orderBy('name_en')
                ->get()
        )->additional(['message' => 'Category order updated.']);
    }
}
