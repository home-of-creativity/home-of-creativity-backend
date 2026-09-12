<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePricingPackageRequest;
use App\Http\Requests\UpdatePricingPackageRequest;
use App\Http\Resources\PricingPackageResource;
use App\Models\PricingPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingPackageController extends Controller
{
    public function index(Request $request)
    {
        $query = PricingPackage::query()
            ->with(['subcategory.category'])
            ->orderBy('sort_order')
            ->orderBy('name_en');

        if ($request->filled('subcategory_id')) {
            $query->where('subcategory_id', (int) $request->input('subcategory_id'));
        }

        if ($request->filled('category_id')) {
            $query->whereHas('subcategory', fn ($q) => $q->where('category_id', (int) $request->input('category_id')));
        }

        return PricingPackageResource::collection($query->get())
            ->additional(['message' => 'ok']);
    }

    public function store(StorePricingPackageRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['sort_order'] = $data['sort_order'] ?? ((int) PricingPackage::query()
            ->where('subcategory_id', $data['subcategory_id'])
            ->max('sort_order')) + 1;
        $data['is_published'] = $data['is_published'] ?? true;
        $data['features'] = $data['features'] ?? [];

        $package = PricingPackage::query()->create($data);

        return PricingPackageResource::make($package->load(['subcategory.category']))
            ->additional(['message' => 'Created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdatePricingPackageRequest $request, PricingPackage $pricingPackage): PricingPackageResource
    {
        $pricingPackage->fill($request->validated())->save();

        return PricingPackageResource::make($pricingPackage->fresh()->load(['subcategory.category']))
            ->additional(['message' => 'Updated.']);
    }

    public function destroy(PricingPackage $pricingPackage)
    {
        $pricingPackage->delete();

        return response()->json([
            'data' => null,
            'message' => 'Deleted.',
        ]);
    }

    public function destroyAll(Request $request)
    {
        $query = PricingPackage::query();
        if ($request->filled('subcategory_id')) {
            $query->where('subcategory_id', (int) $request->input('subcategory_id'));
        }
        $deleted = $query->delete();

        return response()->json([
            'data' => ['deleted' => $deleted],
            'message' => 'Packages deleted.',
        ]);
    }

    public function move(Request $request, PricingPackage $pricingPackage)
    {
        $direction = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ])['direction'];

        $items = PricingPackage::query()
            ->where('subcategory_id', $pricingPackage->subcategory_id)
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get();

        $index = $items->search(fn (PricingPackage $item) => $item->id === $pricingPackage->id);
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

        return PricingPackageResource::collection(
            PricingPackage::query()
                ->where('subcategory_id', $pricingPackage->subcategory_id)
                ->with(['subcategory.category'])
                ->orderBy('sort_order')
                ->orderBy('name_en')
                ->get()
        )->additional(['message' => 'Package order updated.']);
    }
}
