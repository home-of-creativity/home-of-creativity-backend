<?php

namespace App\Http\Controllers;

use App\Services\SocialLandingFeed;
use Illuminate\Http\JsonResponse;

class SocialFeedController extends Controller
{
    public function instagram(SocialLandingFeed $feed): JsonResponse
    {
        return response()->json([
            'data' => $feed->instagram(),
            'message' => 'ok',
        ]);
    }

    public function facebook(SocialLandingFeed $feed): JsonResponse
    {
        return response()->json([
            'data' => $feed->facebook(),
            'message' => 'ok',
        ]);
    }
}
