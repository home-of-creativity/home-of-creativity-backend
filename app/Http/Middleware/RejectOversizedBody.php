<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Answer 413 before a huge report body is parsed. The edge and PHP limits stay aligned above this. */
class RejectOversizedBody
{
    public const MESSAGE = 'الملف أكبر من الحد. صغّر الصور ثم أعد المحاولة.';

    public function handle(Request $request, Closure $next): Response
    {
        $length = (int) $request->server('CONTENT_LENGTH');
        $max = (int) config('reports.max_body_bytes', 52 * 1024 * 1024);
        if ($max > 0 && $length > $max) {
            return response()->json(['message' => self::MESSAGE], 413);
        }

        return $next($request);
    }
}
