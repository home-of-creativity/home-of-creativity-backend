<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

class AuthController extends Controller
{
    public function register(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'message' => 'Accounts are created only through the Telegram bot.',
        ], 403);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('dashboard')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => UserResource::make($user),
            ],
            'message' => 'ok',
        ])->cookie($this->staffCookie($request, $token));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'data' => null,
            'message' => 'Logged out.',
        ])->withoutCookie('hoc_staff');
    }

    private function staffCookie(Request $request, string $token): Cookie
    {
        return Cookie::create('hoc_staff')
            ->withValue($token)
            ->withExpires(now()->addHours(12))
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite('lax');
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user())->additional(['message' => 'ok']);
    }
}
