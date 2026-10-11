<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile app client side. The signed-in user must own a client row; that row
 * is shared with the controller as the `appClient` request attribute so every
 * query is scoped to it.
 */
class EnsureAppClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user()?->client()->first();
        if (! $client instanceof Client) {
            return response()->json([
                'data' => null,
                'code' => 'no_client_account',
                'message' => 'This account has no client side.',
            ], 403);
        }

        $request->attributes->set('appClient', $client);

        return $next($request);
    }
}
