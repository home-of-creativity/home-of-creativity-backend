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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ClientReportController extends Controller
{
    /** Report Word and PDF files are private: only staff with ops.reports read them through the API. */
    private const DISK = 'local';

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
        $reports = $client->reports()->with('attachments')->latest('updated_at')->get();

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
        $publishing = $request->boolean('publish');
        abort_if($publishing && blank($client->google_drive_folder_id), 422, 'Assign a Drive folder to this client first.');

        $report = $client->reports()->create([
            'title' => $request->validated('title'),
            'body' => (string) ($request->validated('body') ?? ''),
        ]);
        $this->storeFiles($request, $report);

        [$report, $driveError] = $this->publish($publish, $report, $publishing);

        return ClientReportResource::make($report)
            ->additional(['message' => 'Created.', 'drive_error' => $driveError])
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreClientReportRequest $request, ClientReport $clientReport, PublishClientReport $publish): ClientReportResource
    {
        $report = $clientReport;
        $report->fill([
            'title' => $request->validated('title'),
            'body' => (string) ($request->validated('body') ?? $report->body ?? ''),
        ])->save();
        $this->storeFiles($request, $report);

        $remove = $request->input('remove_attachment_ids', []);
        if (is_array($remove) && $remove !== []) {
            $report->attachments()->whereIn('id', $remove)->get()->each(function (ClientReportAttachment $file): void {
                Storage::disk('public')->delete($file->path);
                $file->delete();
            });
        }

        [$report, $driveError] = $this->publish($publish, $report, $request->boolean('publish'));

        return ClientReportResource::make($report)
            ->additional(['message' => 'Updated.', 'drive_error' => $driveError]);
    }

    /**
     * The saved Word file, loaded into the dashboard editor.
     */
    public function document(ClientReport $clientReport): StreamedResponse
    {
        abort_unless(filled($clientReport->document_path) && Storage::disk(self::DISK)->exists($clientReport->document_path), 404);

        return Storage::disk(self::DISK)->download(
            $clientReport->document_path,
            $clientReport->title.'.docx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        );
    }

    /**
     * The PDF rendered in the browser at the last save.
     */
    public function pdf(ClientReport $clientReport): StreamedResponse
    {
        abort_unless(filled($clientReport->pdf_path) && Storage::disk(self::DISK)->exists($clientReport->pdf_path), 404);

        return Storage::disk(self::DISK)->download($clientReport->pdf_path, $clientReport->title.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function destroy(ClientReport $clientReport): JsonResponse
    {
        $report = $clientReport;
        $report->load('attachments');
        foreach (['cover_path', 'watermark_path'] as $column) {
            if (filled($report->{$column})) {
                Storage::disk('public')->delete($report->{$column});
            }
        }
        Storage::disk(self::DISK)->deleteDirectory('reports/'.$report->id);
        foreach ($report->attachments as $file) {
            Storage::disk('public')->delete($file->path);
        }
        $report->delete();

        return response()->json([
            'data' => null,
            'message' => 'Deleted.',
        ]);
    }

    private function storeFiles(StoreClientReportRequest $request, ClientReport $report): void
    {
        $paths = [];
        if ($request->hasFile('document')) {
            $paths['document_path'] = $request->file('document')->storeAs('reports/'.$report->id, 'report.docx', self::DISK);
        }
        if ($request->hasFile('pdf')) {
            $paths['pdf_path'] = $request->file('pdf')->storeAs('reports/'.$report->id, 'report.pdf', self::DISK);
        }
        if ($paths !== []) {
            $report->forceFill($paths)->save();
        }

        foreach ($request->file('attachments', []) as $file) {
            $report->attachments()->create([
                'original_name' => $file->getClientOriginalName(),
                'path' => $file->store('reports/'.$report->id, 'public'),
                'size' => $file->getSize() ?: 0,
            ]);
        }
    }

    /**
     * Publish to Drive when asked. A Drive failure keeps the saved report and returns the reason,
     * so the editor stays on this report and a retry updates it instead of creating a duplicate.
     *
     * @return array{0: ClientReport, 1: ?string}
     */
    private function publish(PublishClientReport $publish, ClientReport $report, bool $publishing): array
    {
        $report = $report->fresh(['attachments', 'client']) ?? $report;
        if (! $publishing) {
            return [$report, null];
        }

        try {
            return [$publish->handle($report), null];
        } catch (HttpException $exception) {
            return [$report->fresh(['attachments', 'client']) ?? $report, $exception->getMessage() ?: 'Could not upload the report.'];
        }
    }
}
