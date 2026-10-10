<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplaintController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Complaint::query()
            ->with('client:id,name,company_name,phone')
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Complaint $row): array => [
                'id' => $row->id,
                'title' => $row->title,
                'description' => $row->description,
                'status' => $row->status,
                'has_image' => filled($row->image_path),
                'has_audio' => filled($row->audio_path),
                'client_name' => $row->client?->name,
                'company_name' => $row->client?->company_name,
                'phone' => $row->client?->phone,
                'created_at' => $row->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function update(Request $request, Complaint $complaint): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:open,reviewed'],
        ]);
        $complaint->status = $data['status'];
        $complaint->reviewed_at = $data['status'] === 'reviewed' ? now() : null;
        $complaint->save();

        return response()->json([
            'data' => [
                'id' => $complaint->id,
                'status' => $complaint->status,
            ],
        ]);
    }

    public function file(Complaint $complaint, string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, ['image', 'audio'], true), 404);
        $path = $kind === 'image' ? $complaint->image_path : $complaint->audio_path;
        $mime = $kind === 'image' ? $complaint->image_mime : $complaint->audio_mime;
        abort_unless(is_string($path) && $path !== '' && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, basename($path), [
            'Content-Type' => is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream',
        ]);
    }
}
