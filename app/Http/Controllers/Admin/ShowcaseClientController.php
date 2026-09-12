<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShowcaseClientRequest;
use App\Http\Requests\UpdateShowcaseClientRequest;
use App\Http\Resources\ShowcaseClientResource;
use App\Models\ShowcaseClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ShowcaseClientController extends Controller
{
    public function index()
    {
        return ShowcaseClientResource::collection(
            ShowcaseClient::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->paginate(50)
        )->additional(['message' => 'ok']);
    }

    public function store(StoreShowcaseClientRequest $request): JsonResponse
    {
        $data = $request->safe()->except('logo');
        $data['sort_order'] = $data['sort_order'] ?? ((int) ShowcaseClient::query()->max('sort_order')) + 1;
        $data['is_published'] = $data['is_published'] ?? true;

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('portfolio/clients', 'public');
        }

        $client = ShowcaseClient::query()->create($data);

        return ShowcaseClientResource::make($client)
            ->additional(['message' => 'Created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateShowcaseClientRequest $request, ShowcaseClient $showcaseClient): ShowcaseClientResource
    {
        $data = $request->safe()->except('logo');

        if ($request->hasFile('logo')) {
            if ($showcaseClient->logo_path) {
                Storage::disk('public')->delete($showcaseClient->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('portfolio/clients', 'public');
        }

        $showcaseClient->fill($data)->save();

        return ShowcaseClientResource::make($showcaseClient->fresh())
            ->additional(['message' => 'Updated.']);
    }

    public function destroy(ShowcaseClient $showcaseClient)
    {
        if ($showcaseClient->logo_path) {
            Storage::disk('public')->delete($showcaseClient->logo_path);
        }

        $showcaseClient->delete();

        return response()->json([
            'data' => null,
            'message' => 'Deleted.',
        ]);
    }

    public function destroyAll()
    {
        ShowcaseClient::query()->each(function (ShowcaseClient $client): void {
            if ($client->logo_path) {
                Storage::disk('public')->delete($client->logo_path);
            }
        });

        $count = ShowcaseClient::query()->count();
        ShowcaseClient::query()->delete();

        return response()->json([
            'data' => ['deleted' => $count],
            'message' => 'All clients deleted.',
        ]);
    }
}
