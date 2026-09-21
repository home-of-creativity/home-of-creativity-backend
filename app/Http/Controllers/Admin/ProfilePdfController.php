<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProfilePdfRequest;
use App\Models\OpsSetting;
use App\Support\ProfilePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ProfilePdfController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => ProfilePdf::payload(),
            'message' => 'ok',
        ])->header('Cache-Control', 'no-store');
    }

    public function store(StoreProfilePdfRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $stored = $file?->store('profile', 'local');
        abort_unless(is_string($stored) && $stored !== '', 422, 'Failed to store profile PDF.');

        $previous = OpsSetting::getValue('profile_pdf_path');
        if (filled($previous) && $previous !== $stored && Storage::disk('local')->exists($previous)) {
            Storage::disk('local')->delete($previous);
        }

        $original = $file?->getClientOriginalName() ?: 'profile.pdf';

        OpsSetting::setValue('profile_pdf_path', $stored);
        OpsSetting::setValue('profile_pdf_name', $original);
        OpsSetting::setValue('profile_pdf_updated_at', now()->toIso8601String());

        return response()->json([
            'data' => ProfilePdf::payload(),
            'message' => 'PDF saved.',
        ]);
    }

    public function destroy(): JsonResponse
    {
        $previous = OpsSetting::getValue('profile_pdf_path');
        if (filled($previous) && Storage::disk('local')->exists($previous)) {
            Storage::disk('local')->delete($previous);
        }

        OpsSetting::setValue('profile_pdf_path', '');
        OpsSetting::setValue('profile_pdf_name', '');
        OpsSetting::setValue('profile_pdf_updated_at', '');

        return response()->json([
            'data' => ProfilePdf::payload(),
            'message' => 'PDF deleted.',
        ]);
    }
}
