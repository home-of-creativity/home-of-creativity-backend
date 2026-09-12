<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePricingCategoryRequest;
use App\Http\Requests\UpdatePricingCategoryRequest;
use App\Http\Resources\PricingCategoryResource;
use App\Models\PricingCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingCategoryController extends Controller
{
    public function index()
    {
        return PricingCategoryResource::collection(
            PricingCategory::query()
                ->withCount('subcategories')
                ->orderBy('sort_order')
                ->orderBy('name_en')
                ->get()
        )->additional(['message' => 'ok']);
    }

    public function store(StorePricingCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['sort_order'] = $data['sort_order'] ?? ((int) PricingCategory::query()->max('sort_order')) + 1;
        $data['is_published'] = $data['is_published'] ?? true;

        $category = PricingCategory::query()->create($data);

        return PricingCategoryResource::make($category)
            ->additional(['message' => 'Created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdatePricingCategoryRequest $request, PricingCategory $pricingCategory): PricingCategoryResource
    {
        $pricingCategory->fill($request->validated())->save();

        return PricingCategoryResource::make($pricingCategory->fresh())
            ->additional(['message' => 'Updated.']);
    }

    public function destroy(PricingCategory $pricingCategory)
    {
        $pricingCategory->delete();

        return response()->json([
            'data' => null,
            'message' => 'Deleted.',
        ]);
    }

    public function destroyAll()
    {
        PricingCategory::query()->delete();

        return response()->json([
            'data' => ['deleted' => true],
            'message' => 'All pricing categories deleted.',
        ]);
    }

    public function move(Request $request, PricingCategory $pricingCategory)
    {
        $direction = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ])['direction'];

        $items = PricingCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get();

        $index = $items->search(fn (PricingCategory $item) => $item->id === $pricingCategory->id);
        if ($index === false) {
            abort(404);
        }

        $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swapIndex >= 0 && $swapIndex < $items->count()) {
            $current = $items[$index];
            $neighbor = $items[$swapIndex];
            $currentOrder = $current->sort_order;
            $current->forceFill(['sort_order' => $neighbor->sort_order])->save();
            $neighbor->forceFill(['sort_order' => $currentOrder])->save();
        }

        return PricingCategoryResource::collection(
            PricingCategory::query()
                ->withCount('subcategories')
                ->orderBy('sort_order')
                ->orderBy('name_en')
                ->get()
        )->additional(['message' => 'Category order updated.']);
    }
}
