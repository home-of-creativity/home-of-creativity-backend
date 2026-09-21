<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreArticleRequest;
use App\Http\Requests\UpdateArticleRequest;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    public function index()
    {
        return ArticleResource::collection(
            Article::query()
                ->orderBy('sort_order')
                ->orderByRaw('COALESCE(published_at, created_at) DESC')
                ->orderByDesc('id')
                ->paginate(50)
        )->additional(['message' => 'ok']);
    }

    public function show(Article $article): ArticleResource
    {
        return ArticleResource::make($article)
            ->additional(['message' => 'ok']);
    }

    public function store(StoreArticleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['title_en']);
        $data['sort_order'] = $data['sort_order'] ?? ((int) Article::query()->max('sort_order')) + 1;
        $data['is_published'] = $data['is_published'] ?? true;
        $data['published_at'] = $this->resolvePublishedAt($data['published_at'] ?? null, $data['is_published']);

        $article = Article::query()->create($data);

        return ArticleResource::make($article)
            ->additional(['message' => 'Created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateArticleRequest $request, Article $article): ArticleResource
    {
        $data = $request->validated();

        if (array_key_exists('slug', $data)) {
            $data['slug'] = $this->uniqueSlug($data['slug'] ?: $article->title_en, $article->id);
        }

        if (array_key_exists('published_at', $data) || array_key_exists('is_published', $data)) {
            $data['published_at'] = $this->resolvePublishedAt(
                $data['published_at'] ?? $article->published_at,
                $data['is_published'] ?? $article->is_published,
            );
        }

        $article->fill($data)->save();

        return ArticleResource::make($article->fresh())
            ->additional(['message' => 'Updated.']);
    }

    public function destroy(Article $article): JsonResponse
    {
        $article->delete();

        return response()->json([
            'data' => null,
            'message' => 'Deleted.',
        ]);
    }

    public function destroyAll(): JsonResponse
    {
        $count = Article::query()->count();
        Article::query()->delete();

        return response()->json([
            'data' => ['deleted' => $count],
            'message' => 'All articles deleted.',
        ]);
    }

    /**
     * Publishing without an explicit date stamps "now" so the public list can
     * order the newest monthly article first; unpublished drafts keep null.
     */
    private function resolvePublishedAt(mixed $value, bool $isPublished): ?Carbon
    {
        if ($value) {
            return Carbon::parse($value);
        }

        return $isPublished ? Carbon::now() : null;
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = Str::slug($base);
        if ($slug === '') {
            $slug = 'article';
        }

        $candidate = $slug;
        $suffix = 2;
        while (
            Article::query()
                ->where('slug', $candidate)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = "{$slug}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
