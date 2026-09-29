<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePortfolioProjectRequest;
use App\Http\Requests\UpdatePortfolioProjectRequest;
use App\Http\Resources\PortfolioProjectResource;
use App\Models\PortfolioProject;
use App\Models\PortfolioProjectImage;
use App\Support\LegalHtml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PortfolioProjectController extends Controller
{
    public function index()
    {
        return PortfolioProjectResource::collection(
            PortfolioProject::query()
                ->with(['category', 'images', 'related'])
                ->orderBy('sort_order')
                ->orderByDesc('id')
                ->paginate(50)
        )->additional(['message' => 'ok']);
    }

    public function store(StorePortfolioProjectRequest $request): JsonResponse
    {
        $data = $this->cleanBody($request->safe()->except(['image', 'gallery', 'remove_gallery_ids', 'related_ids', 'related_sync']));
        $data['sort_order'] = $data['sort_order'] ?? ((int) PortfolioProject::query()->max('sort_order')) + 1;
        $data['is_published'] = $data['is_published'] ?? true;
        $data['featured'] = $data['featured'] ?? false;

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('portfolio/projects', 'public');
        }

        $project = PortfolioProject::query()->create($data);
        $this->syncGallery($project, $request);
        $this->syncRelated($project, $request);
        $project->load(['category', 'images', 'related']);

        return PortfolioProjectResource::make($project)
            ->additional(['message' => 'Created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdatePortfolioProjectRequest $request, PortfolioProject $portfolioProject): PortfolioProjectResource
    {
        $data = $this->cleanBody($request->safe()->except(['image', 'gallery', 'remove_gallery_ids', 'related_ids', 'related_sync']));

        if ($request->hasFile('image')) {
            if ($portfolioProject->image_path) {
                Storage::disk('public')->delete($portfolioProject->image_path);
            }
            $data['image_path'] = $request->file('image')->store('portfolio/projects', 'public');
        }

        $portfolioProject->fill($data)->save();
        $this->syncGallery($portfolioProject, $request);
        $this->syncRelated($portfolioProject, $request);
        $portfolioProject->load(['category', 'images', 'related']);

        return PortfolioProjectResource::make($portfolioProject)
            ->additional(['message' => 'Updated.']);
    }

    public function destroy(PortfolioProject $portfolioProject)
    {
        $this->deleteProjectFiles($portfolioProject);
        $portfolioProject->delete();

        return response()->json([
            'data' => null,
            'message' => 'Deleted.',
        ]);
    }

    public function destroyAll()
    {
        PortfolioProject::query()->each(function (PortfolioProject $project): void {
            $this->deleteProjectFiles($project);
        });

        $count = PortfolioProject::query()->count();
        PortfolioProject::query()->delete();

        return response()->json([
            'data' => ['deleted' => $count],
            'message' => 'All projects deleted.',
        ]);
    }

    /**
     * The project page shows this HTML as is, so only a safe subset is kept.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function cleanBody(array $data): array
    {
        foreach (['body_en', 'body_ar'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $clean = LegalHtml::clean((string) $data[$field]);
                $data[$field] = $clean === '' ? null : $clean;
            }
        }

        return $data;
    }

    private function syncRelated(PortfolioProject $project, Request $request): void
    {
        if (! $request->has('related_ids') && ! $request->boolean('related_sync')) {
            return;
        }

        $ids = collect($request->input('related_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0 && $id !== $project->id)
            ->unique()
            ->values();

        $project->related()->sync($ids->mapWithKeys(fn (int $id, int $index) => [$id => ['sort_order' => $index]])->all());
    }

    private function syncGallery(PortfolioProject $project, Request $request): void
    {
        if ($request->has('remove_gallery_ids')) {
            $ids = collect($request->input('remove_gallery_ids'))
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->all();

            PortfolioProjectImage::query()
                ->where('portfolio_project_id', $project->id)
                ->whereIn('id', $ids)
                ->get()
                ->each(function (PortfolioProjectImage $image): void {
                    Storage::disk('public')->delete($image->image_path);
                    $image->delete();
                });
        }

        if (! $request->hasFile('gallery')) {
            return;
        }

        $maxOrder = (int) $project->images()->max('sort_order');

        foreach ($request->file('gallery') as $index => $file) {
            $project->images()->create([
                'image_path' => $file->store('portfolio/projects/gallery', 'public'),
                'sort_order' => $maxOrder + $index + 1,
            ]);
        }
    }

    private function deleteProjectFiles(PortfolioProject $project): void
    {
        if ($project->image_path) {
            Storage::disk('public')->delete($project->image_path);
        }

        $project->images()->each(function (PortfolioProjectImage $image): void {
            Storage::disk('public')->delete($image->image_path);
        });
    }
}
