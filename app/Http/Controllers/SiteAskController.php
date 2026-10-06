<?php

namespace App\Http\Controllers;

use App\Services\GeminiService;
use App\Services\SiteGuide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class SiteAskController extends Controller
{
    public function __invoke(Request $request, GeminiService $gemini, SiteGuide $guide): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'locale' => ['nullable', 'in:ar,en'],
            'history' => ['nullable', 'array', 'max:6'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.text' => ['required', 'string', 'max:800'],
        ]);

        $question = trim($data['question']);
        if ($question === '') {
            throw ValidationException::withMessages([
                'question' => 'A question is required.',
            ]);
        }

        $locale = ($data['locale'] ?? 'ar') === 'en' ? 'en' : 'ar';
        $history = array_values($data['history'] ?? []);
        $remember = $history === []
            ? 'site-ask:v3:'.sha1($locale.'|'.mb_strtolower($question))
            : null;

        try {
            $answer = $remember === null
                ? $gemini->answerSiteQuestion($question, $locale, $guide->brief(), $history)
                : Cache::remember($remember, now()->addMinutes(10), function () use ($gemini, $guide, $question, $locale): string {
                    return $gemini->answerSiteQuestion($question, $locale, $guide->brief(), []);
                });
        } catch (ValidationException) {
            return response()->json([
                'data' => null,
                'message' => 'unavailable',
            ], 503);
        }

        return response()->json([
            'data' => ['answer' => $answer],
            'message' => 'ok',
        ]);
    }
}
