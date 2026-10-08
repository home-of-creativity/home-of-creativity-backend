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
        $page = $request->string('page_token')->trim()->toString() ?: null;
        $lookup = $request->string('folder')->trim()->toString();
        if ($lookup !== '') {
            $one = $drive->folder($lookup);
            abort_if($one === null, 422, $drive->lastError() ?? 'Could not open that Drive folder.');

            return response()->json([
                'data' => [$one],
                'meta' => [
                    'parent_id' => null,
                    'next_page_token' => null,
                ],
                'message' => 'ok',
            ]);
        }

        $term = $request->string('q')->trim()->toString();
        if ($term !== '') {
            if (mb_strlen($term) < 2) {
                return response()->json([
                    'data' => [],
                    'meta' => [
                        'parent_id' => null,
                        'next_page_token' => null,
                    ],
                    'message' => 'ok',
                ]);
            }
            $listed = $drive->searchFolders($term, $page);
            abort_if($listed === null, 422, $drive->lastError() ?? 'Could not search Drive folders.');

            return response()->json([
                'data' => $listed['folders'],
                'meta' => [
                    'parent_id' => null,
                    'next_page_token' => $listed['next_page_token'],
                ],
                'message' => 'ok',
            ]);
        }

        $parent = $request->string('parent')->trim()->toString();
        $listed = $drive->listFolders($parent !== '' ? $parent : null, $page);
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
                'parent_id' => filled($parent) && $parent !== 'root'
                    ? (string) $parent
                    : (config('services.google.drive_parent_folder_id') ?: null),
            ],
            'message' => 'Drive folder created.',
        ], 201);
    }
}
