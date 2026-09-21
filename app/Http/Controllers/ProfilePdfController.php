<?php

namespace App\Http\Controllers;

use App\Support\ProfilePdf;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfilePdfController extends Controller
{
    public function show()
    {
        return response()->json([
            'data' => ProfilePdf::payload(),
            'message' => 'ok',
        ])->header('Cache-Control', 'no-store');
    }

    public function file(): BinaryFileResponse
    {
        $absolute = ProfilePdf::absolutePath();
        abort_unless(is_string($absolute), 404, 'Profile PDF is not configured.');

        $name = ProfilePdf::downloadName();

        return response()->file($absolute, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'public, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
