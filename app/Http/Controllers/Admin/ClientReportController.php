<?php

namespace App\Http\Controllers\Admin;

use App\Actions\PublishClientReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientReportRequest;
use App\Http\Resources\ClientReportResource;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ClientReportAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ClientReportController extends Controller
{
    public function clients()
    {
        $clients = Client::query()
            ->visibleOnDashboard()
            ->withCount('reports')
            ->orderBy('name')
            ->paginate(30);

        return ClientResource::collection($clients)->additional(['message' => 'ok']);
    }

    public function index(Client $client)
    {
        $reports = $client->reports()->with('attachments')->latest()->get();

        return ClientReportResource::collection($reports)->additional([
            'message' => 'ok',
            'client' => ClientResource::make($client)->resolve(),
        ]);
    }

    public function show(ClientReport $clientReport): ClientReportResource
    {
        return ClientReportResource::make($clientReport->load(['attachments', 'client']))
            ->additional(['message' => 'ok']);
    }

    public function store(StoreClientReportRequest $request, Client $client, PublishClientReport $publish): JsonResponse
    {
        abort_unless(filled($client->google_drive_folder_id), 422, 'Assign a Drive folder to this client first.');

        $report = $client->reports()->create([
            'title' => $request->validated('title'),
            'header' => $request->validated('header'),
            'footer' => $request->validated('footer'),
            'body' => $request->validated('body'),
        ]);

        if ($request->hasFile('cover')) {
            $report->forceFill([
                'cover_path' => $request->file('cover')->store('reports/'.$report->id, 'public'),
            ])->save();
        }

        if ($request->hasFile('watermark')) {
            $report->forceFill([
                'watermark_path' => $request->file('watermark')->store('reports/'.$report->id, 'public'),
            ])->save();
        }

        foreach ($request->file('attachments', []) as $file) {
            $report->attachments()->create([
                'original_name' => $file->getClientOriginalName(),
                'path' => $file->store('reports/'.$report->id, 'public'),
                'size' => $file->getSize() ?: 0,
            ]);
        }

        $report = $publish->handle($report->fresh(['attachments', 'client']));

        return ClientReportResource::make($report)
            ->additional(['message' => 'Created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreClientReportRequest $request, ClientReport $clientReport, PublishClientReport $publish): ClientReportResource
    {
        $report = $clientReport;
        $report->fill($request->safe()->only(['title', 'header', 'footer', 'body']))->save();

        if ($request->hasFile('cover')) {
            if (filled($report->cover_path)) {
                Storage::disk('public')->delete($report->cover_path);
            }
            $report->forceFill([
                'cover_path' => $request->file('cover')->store('reports/'.$report->id, 'public'),
            ])->save();
        } elseif ($request->boolean('remove_cover') && filled($report->cover_path)) {
            Storage::disk('public')->delete($report->cover_path);
            $report->forceFill(['cover_path' => null])->save();
        }

        if ($request->hasFile('watermark')) {
            if (filled($report->watermark_path)) {
                Storage::disk('public')->delete($report->watermark_path);
            }
            $report->forceFill([
                'watermark_path' => $request->file('watermark')->store('reports/'.$report->id, 'public'),
            ])->save();
        } elseif ($request->boolean('remove_watermark') && filled($report->watermark_path)) {
            Storage::disk('public')->delete($report->watermark_path);
            $report->forceFill(['watermark_path' => null])->save();
        }

        $remove = $request->input('remove_attachment_ids', []);
        if (is_array($remove) && $remove !== []) {
            $report->attachments()->whereIn('id', $remove)->get()->each(function (ClientReportAttachment $file): void {
                Storage::disk('public')->delete($file->path);
                $file->delete();
            });
        }

        foreach ($request->file('attachments', []) as $file) {
            $report->attachments()->create([
                'original_name' => $file->getClientOriginalName(),
                'path' => $file->store('reports/'.$report->id, 'public'),
                'size' => $file->getSize() ?: 0,
            ]);
        }

        return ClientReportResource::make($publish->handle($report->fresh(['attachments', 'client'])))
            ->additional(['message' => 'Updated.']);
    }

    public function destroy(ClientReport $clientReport): JsonResponse
    {
        $report = $clientReport;
        $report->load('attachments');
        if (filled($report->cover_path)) {
            Storage::disk('public')->delete($report->cover_path);
        }
        if (filled($report->watermark_path)) {
            Storage::disk('public')->delete($report->watermark_path);
        }
        foreach ($report->attachments as $file) {
            Storage::disk('public')->delete($file->path);
        }
        $report->delete();

        return response()->json([
            'data' => null,
            'message' => 'Deleted.',
        ]);
    }
}
