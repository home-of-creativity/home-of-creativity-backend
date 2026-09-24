<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDriveFolderRequest;
use App\Services\GoogleDriveClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriveFolderController extends Controller
{
    public function index(Request $request, GoogleDriveClient $drive): JsonResponse
    {
        $parent = $request->string('parent')->trim()->toString();
        $listed = $drive->listFolders($parent !== '' ? $parent : null, $request->string('page_token')->trim()->toString() ?: null);
        abort_if($listed === null, 422, $drive->lastError() ?? 'Could not list Drive folders.');

        return response()->json([
            'data' => $listed['folders'],
            'meta' => [
                'parent_id' => $parent !== '' ? $parent : null,
                'next_page_token' => $listed['next_page_token'],
            ],
            'message' => 'ok',
        ]);
    }

    public function store(CreateDriveFolderRequest $request, GoogleDriveClient $drive): JsonResponse
    {
        $parent = $request->validated('parent');
        $id = $drive->createFolder((string) $request->validated('name'), is_string($parent) ? $parent : null);
        abort_if($id === null, 422, $drive->lastError() ?? 'Could not create the Drive folder.');

        return response()->json([
            'data' => [
                'id' => $id,
                'name' => (string) $request->validated('name'),
                'parent_id' => filled($parent) ? (string) $parent : 'root',
            ],
            'message' => 'Drive folder created.',
        ], 201);
    }
}
