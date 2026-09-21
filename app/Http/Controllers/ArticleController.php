<?php

namespace App\Http\Controllers;

use App\Http\Resources\ArticleResource;
use App\Models\Article;

class ArticleController extends Controller
{
    public function index()
    {
        return ArticleResource::collection(
            Article::query()
                ->published()
                ->orderByRaw('COALESCE(published_at, created_at) DESC')
                ->orderByDesc('id')
                ->get()
        )->additional(['message' => 'ok']);
    }

    public function show(Article $article)
    {
        if (! $article->is_published) {
            abort(404);
        }

        return ArticleResource::make($article)
            ->additional(['message' => 'ok']);
    }
}
