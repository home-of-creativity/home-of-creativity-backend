<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $allowed = false;

        foreach ($abilities as $ability) {
            if ($request->user()?->canAbility($ability)) {
                $allowed = true;
                break;
            }
        }

        abort_unless($allowed, 403, 'You do not have access to this section.');

        return $next($request);
    }
}
