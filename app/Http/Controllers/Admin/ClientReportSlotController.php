<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientReportSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientReportSlotController extends Controller
{
    /** Plan pictures stay private. They are a calendar note, not a published post. */
    private const DISK = 'local';

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'client_id' => ['nullable', 'integer'],
        ]);
        $from = Carbon::parse($data['from'])->timezone('Asia/Damascus');
        $to = Carbon::parse($data['to'])->timezone('Asia/Damascus');

        $slots = ClientReportSlot::query()
            ->with('client:id,name,company_name')
            ->where('scheduled_at', '>=', $from->toDateTimeString())
            ->where('scheduled_at', '<', $to->toDateTimeString())
            ->when(filled($data['client_id'] ?? null), fn ($query) => $query->where('client_id', $data['client_id']))
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $slots->map(fn (ClientReportSlot $slot): array => $this->payload($slot))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $slot = ClientReportSlot::query()->create($this->fields($request));
        $this->storeImage($request, $slot);
        $slot = $slot->fresh(['client']);
        abort_if($slot === null, 404);

        return response()->json(['data' => $this->payload($slot)], 201);
    }

    public function update(Request $request, ClientReportSlot $slot): JsonResponse
    {
        $slot->update($this->fields($request));
        $this->storeImage($request, $slot);
        $slot = $slot->fresh(['client']);
        abort_if($slot === null, 404);

        return response()->json(['data' => $this->payload($slot)]);
    }

    public function destroy(ClientReportSlot $slot): JsonResponse
    {
        $slot->delete();

        return response()->json(['data' => null]);
    }

    public function image(ClientReportSlot $slot): StreamedResponse
    {
        abort_unless(filled($slot->image_path) && Storage::disk(self::DISK)->exists($slot->image_path), 404);

        return Storage::disk(self::DISK)->response($slot->image_path, $slot->image_name ?: 'image');
    }

    /**
     * @return array{client_id: int, kind: string, scheduled_at: string, caption: ?string}
     */
    private function fields(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'kind' => ['required', Rule::in(['image', 'post', 'reel'])],
            'scheduled_at' => ['required', 'date'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'max:8192'],
        ]);

        return [
            'client_id' => (int) $data['client_id'],
            'kind' => $data['kind'],
            'scheduled_at' => $data['scheduled_at'],
            'caption' => filled($data['caption'] ?? null) ? $data['caption'] : null,
        ];
    }

    private function storeImage(Request $request, ClientReportSlot $slot): void
    {
        $file = $request->file('image');
        if (! $file instanceof UploadedFile) {
            return;
        }

        if (filled($slot->image_path)) {
            Storage::disk(self::DISK)->delete($slot->image_path);
        }
        $slot->update([
            'image_path' => $file->store('plan-slots/'.$slot->id, self::DISK),
            'image_name' => mb_substr($file->getClientOriginalName(), 0, 190),
        ]);
    }

    /**
     * @return array{id: int, client_id: int, client_name: string, kind: string, scheduled_at: string|null, caption: string|null, has_image: bool, image_name: string|null}
     */
    private function payload(ClientReportSlot $slot): array
    {
        $client = $slot->client;

        return [
            'id' => $slot->id,
            'client_id' => $slot->client_id,
            'client_name' => trim((string) ($client?->company_name ?: $client?->name)),
            'kind' => $slot->kind,
            'scheduled_at' => $slot->scheduled_at?->timezone('Asia/Damascus')->toIso8601String(),
            'caption' => $slot->caption,
            'has_image' => filled($slot->image_path),
            'image_name' => $slot->image_name,
        ];
    }
}
