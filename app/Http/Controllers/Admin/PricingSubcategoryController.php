<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePricingSubcategoryRequest;
use App\Http\Requests\UpdatePricingSubcategoryRequest;
use App\Http\Resources\PricingSubcategoryResource;
use App\Models\PricingSubcategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingSubcategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = PricingSubcategory::query()
            ->with('category')
            ->withCount('packages')
            ->orderBy('sort_order')
            ->orderBy('name_en');

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        return PricingSubcategoryResource::collection($query->get())
            ->additional(['message' => 'ok']);
    }

    public function store(StorePricingSubcategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['sort_order'] = $data['sort_order'] ?? ((int) PricingSubcategory::query()
            ->where('category_id', $data['category_id'])
            ->max('sort_order')) + 1;
        $data['is_published'] = $data['is_published'] ?? true;

        $subcategory = PricingSubcategory::query()->create($data);

        return PricingSubcategoryResource::make($subcategory->load('category'))
            ->additional(['message' => 'Created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdatePricingSubcategoryRequest $request, PricingSubcategory $pricingSubcategory): PricingSubcategoryResource
    {
        $pricingSubcategory->fill($request->validated())->save();

        return PricingSubcategoryResource::make($pricingSubcategory->fresh()->load('category'))
            ->additional(['message' => 'Updated.']);
    }

    public function destroy(PricingSubcategory $pricingSubcategory)
    {
        $pricingSubcategory->delete();

        return response()->json([
            'data' => null,
            'message' => 'Deleted.',
        ]);
    }

    public function destroyAll(Request $request)
    {
        $query = PricingSubcategory::query();
        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }
        $deleted = $query->delete();

        return response()->json([
            'data' => ['deleted' => $deleted],
            'message' => 'Subcategories deleted.',
        ]);
    }

    public function move(Request $request, PricingSubcategory $pricingSubcategory)
    {
        $direction = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ])['direction'];

        $items = PricingSubcategory::query()
            ->where('category_id', $pricingSubcategory->category_id)
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get();

        $index = $items->search(fn (PricingSubcategory $item) => $item->id === $pricingSubcategory->id);
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

        return PricingSubcategoryResource::collection(
            PricingSubcategory::query()
                ->where('category_id', $pricingSubcategory->category_id)
                ->with('category')
                ->withCount('packages')
                ->orderBy('sort_order')
                ->orderBy('name_en')
                ->get()
        )->additional(['message' => 'Subcategory order updated.']);
    }
}
