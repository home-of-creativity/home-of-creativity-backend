<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The dashboard sends its session in an httpOnly cookie. A request that already
 * carries Authorization (the bots, scripts, and the mobile app) is left as it is.
 */
class PromoteStaffCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie('hoc_staff');
        if (is_string($token) && $token !== '' && ! $request->bearerToken()) {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }

        return $next($request);
    }
}
