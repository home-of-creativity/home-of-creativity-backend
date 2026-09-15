<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLandingReelRequest;
use App\Http\Requests\UpdateLandingReelRequest;
use App\Http\Resources\LandingReelResource;
use App\Models\LandingReel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class LandingReelController extends Controller
{
    public function index()
    {
        return LandingReelResource::collection(
            LandingReel::query()
                ->orderBy('sort_order')
                ->orderByDesc('id')
                ->paginate(50)
        )->additional(['message' => 'ok']);
    }

    public function store(StoreLandingReelRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['video', 'poster']);
        $data['sort_order'] = $data['sort_order'] ?? ((int) LandingReel::query()->max('sort_order')) + 1;
        $data['is_published'] = $data['is_published'] ?? true;
        $data['video_path'] = $request->file('video')->store('reels', 'public');

        if ($request->hasFile('poster')) {
            $data['poster_path'] = $request->file('poster')->store('reels/posters', 'public');
        }

        $reel = LandingReel::query()->create($data);

        return LandingReelResource::make($reel)
            ->additional(['message' => 'Created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateLandingReelRequest $request, LandingReel $landingReel): LandingReelResource
    {
        $data = $request->safe()->except(['video', 'poster']);

        if ($request->hasFile('video')) {
            $newPath = $request->file('video')->store('reels', 'public');
            Storage::disk('public')->delete($landingReel->video_path);
            $data['video_path'] = $newPath;
        }

        if ($request->hasFile('poster')) {
            if ($landingReel->poster_path) {
                Storage::disk('public')->delete($landingReel->poster_path);
            }
            $data['poster_path'] = $request->file('poster')->store('reels/posters', 'public');
        }

        $landingReel->fill($data)->save();

        return LandingReelResource::make($landingReel->fresh())
            ->additional(['message' => 'Updated.']);
    }

    public function destroy(LandingReel $landingReel)
    {
        $this->deleteFiles($landingReel);
        $landingReel->delete();

        return response()->json([
            'data' => null,
            'message' => 'Deleted.',
        ]);
    }

    public function destroyAll()
    {
        LandingReel::query()->each(function (LandingReel $reel): void {
            $this->deleteFiles($reel);
        });

        $count = LandingReel::query()->count();
        LandingReel::query()->delete();

        return response()->json([
            'data' => ['deleted' => $count],
            'message' => 'All reels deleted.',
        ]);
    }

    private function deleteFiles(LandingReel $reel): void
    {
        Storage::disk('public')->delete($reel->video_path);

        if ($reel->poster_path) {
            Storage::disk('public')->delete($reel->poster_path);
        }
    }
}
