<?php

namespace App\Http\Controllers\Admin;

use App\Actions\RecordReportHistory;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientReportResource;
use App\Http\Resources\ClientReportVersionResource;
use App\Models\ClientReportVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportHistoryController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'report_id' => ['nullable', 'integer'],
            'event' => ['nullable', 'string', Rule::in([
                ClientReportVersion::CREATED,
                ClientReportVersion::EDITED,
                ClientReportVersion::PUBLISHED,
                ClientReportVersion::RESTORED,
                ClientReportVersion::ATTACHMENT_ADDED,
                ClientReportVersion::ATTACHMENT_REMOVED,
                ClientReportVersion::DELETED,
            ])],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $like = '%'.addcslashes($search, '%_\\').'%';

        $versions = ClientReportVersion::query()
            ->with(['user', 'report', 'client'])
            ->when($data['client_id'] ?? null, fn ($query, $id) => $query->where('client_id', $id))
            ->when($data['report_id'] ?? null, fn ($query, $id) => $query->where('client_report_id', $id))
            ->when($data['event'] ?? null, fn ($query, $event) => $query->where('event', $event))
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($like): void {
                $inner->where('title', 'like', $like)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $like))
                    ->orWhereHas('client', fn ($client) => $client->where('name', 'like', $like)->orWhere('company_name', 'like', $like));
            }))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 30));

        return ClientReportVersionResource::collection($versions)->additional(['message' => 'ok']);
    }

    public function document(ClientReportVersion $clientReportVersion): StreamedResponse
    {
        $path = (string) $clientReportVersion->document_path;
        abort_unless($path !== '' && Storage::disk('local')->exists($path), 404, 'This version no longer has its Word file.');
        $stamp = ($clientReportVersion->updated_at ?? now())->setTimezone('Asia/Damascus')->format('Y-m-d H.i');

        return Storage::disk('local')->download(
            $path,
            $clientReportVersion->title.' ('.$stamp.').docx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        );
    }

    public function restore(Request $request, ClientReportVersion $clientReportVersion, RecordReportHistory $history): ClientReportResource
    {
        $report = $history->restore($clientReportVersion, $request->user());

        return ClientReportResource::make($report)->additional(['message' => 'Restored.']);
    }
}
