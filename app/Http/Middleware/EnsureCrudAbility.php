<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCrudAbility
{
    public function handle(Request $request, Closure $next, string ...$resources): Response
    {
        $action = match ($request->method()) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'view',
        };

        $allowed = false;
        foreach ($resources as $resource) {
            if ($request->user()?->canAbility($resource.'.'.$action)) {
                $allowed = true;
                break;
            }
        }

        abort_unless($allowed, 403, 'You do not have access to this section.');

        return $next($request);
    }
}
