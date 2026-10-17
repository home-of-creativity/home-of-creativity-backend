<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientReportSlot;
use App\Models\ClientReportSlotImage;
use App\Models\ShowcaseClient;
use App\Support\ReportCalendarAccess;
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

    private const MAX_IMAGES = 8;

    public function index(Request $request, ReportCalendarAccess $access): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'client_id' => ['nullable', 'integer'],
        ]);
        $from = Carbon::parse($data['from'])->timezone('Asia/Damascus');
        $to = Carbon::parse($data['to'])->timezone('Asia/Damascus');

        $slots = ClientReportSlot::query()
            ->with(['client:id,name,company_name,logo_path', 'calendar:id,name,color', 'images'])
            ->where('scheduled_at', '>=', $from->toDateTimeString())
            ->where('scheduled_at', '<', $to->toDateTimeString())
            ->when(filled($data['client_id'] ?? null), fn ($query) => $query->where('client_id', $data['client_id']))
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (ClientReportSlot $slot): bool => $access->canView($request->user(), $slot->report_calendar_id));

        return response()->json([
            'data' => $slots->map(fn (ClientReportSlot $slot): array => $this->payload($slot))->values(),
        ]);
    }

    public function store(Request $request, ReportCalendarAccess $access): JsonResponse
    {
        $fields = $this->fields($request, $access);
        $slot = ClientReportSlot::query()->create($fields);
        $this->storeImages($request, $slot);
        $this->storeLogo($request, $slot);
        $slot = $slot->fresh(['client', 'calendar', 'images']);
        abort_if($slot === null, 404);

        return response()->json(['data' => $this->payload($slot)], 201);
    }

    public function update(Request $request, ClientReportSlot $slot, ReportCalendarAccess $access): JsonResponse
    {
        abort_unless($access->canView($request->user(), $slot->report_calendar_id), 404);
        $slot->update($this->fields($request, $access));
        $this->forgetImages($request, $slot);
        $this->storeImages($request, $slot);
        $this->storeLogo($request, $slot);
        $slot = $slot->fresh(['client', 'calendar', 'images']);
        abort_if($slot === null, 404);

        return response()->json(['data' => $this->payload($slot)]);
    }

    public function destroy(Request $request, ClientReportSlot $slot, ReportCalendarAccess $access): JsonResponse
    {
        abort_unless($access->canView($request->user(), $slot->report_calendar_id), 404);
        $slot->delete();

        return response()->json(['data' => null]);
    }

    public function image(ClientReportSlot $slot, ClientReportSlotImage $image): StreamedResponse
    {
        abort_unless($image->client_report_slot_id === $slot->id && Storage::disk(self::DISK)->exists($image->path), 404);

        return Storage::disk(self::DISK)->response($image->path, $image->name ?: 'image');
    }

    /**
     * @return array{client_id: int, report_calendar_id: int|null, kind: string, color: string, scheduled_at: string, caption: ?string}
     */
    private function fields(Request $request, ReportCalendarAccess $access): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'calendar_id' => ['nullable', 'integer', 'exists:report_calendars,id'],
            'kind' => ['required', Rule::in(['carousel', 'post', 'reel', 'image'])],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'scheduled_at' => ['required', 'date'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:'.self::MAX_IMAGES],
            'images.*' => ['image', 'max:8192'],
            'image' => ['nullable', 'image', 'max:8192'],
            'remove_image_ids' => ['nullable', 'array'],
            'remove_image_ids.*' => ['integer'],
            'logo' => ['nullable', 'image', 'max:4096'],
        ]);
        $calendarId = filled($data['calendar_id'] ?? null) ? (int) $data['calendar_id'] : null;
        abort_unless($access->canView($request->user(), $calendarId), 403);

        return [
            'client_id' => (int) $data['client_id'],
            'report_calendar_id' => $calendarId,
            'kind' => $data['kind'] === 'image' ? 'carousel' : $data['kind'],
            'color' => strtolower($data['color']),
            'scheduled_at' => $data['scheduled_at'],
            'caption' => filled($data['caption'] ?? null) ? $data['caption'] : null,
        ];
    }

    private function storeImages(Request $request, ClientReportSlot $slot): void
    {
        /** @var list<UploadedFile> $files */
        $files = array_values($request->file('images', []));
        $single = $request->file('image');
        if ($single instanceof UploadedFile) {
            $files[] = $single;
        }
        $files = array_values(array_filter($files, fn ($file): bool => $file instanceof UploadedFile));
        if ($files === []) {
            return;
        }

        $position = (int) $slot->images()->max('position');
        $room = self::MAX_IMAGES - $slot->images()->count();
        foreach (array_slice($files, 0, max(0, $room)) as $file) {
            $position += 1;
            $slot->images()->create([
                'path' => $file->store('plan-slots/'.$slot->id, self::DISK),
                'name' => mb_substr($file->getClientOriginalName(), 0, 190),
                'position' => $position,
            ]);
        }
    }

    private function forgetImages(Request $request, ClientReportSlot $slot): void
    {
        $ids = array_map('intval', (array) $request->input('remove_image_ids', []));
        if ($ids === []) {
            return;
        }
        $slot->images()->whereIn('id', $ids)->get()->each(function (ClientReportSlotImage $image): void {
            Storage::disk(self::DISK)->delete($image->path);
            $image->delete();
        });
    }

    private function storeLogo(Request $request, ClientReportSlot $slot): void
    {
        $file = $request->file('logo');
        $client = $slot->client;
        if (! $file instanceof UploadedFile || $client === null) {
            return;
        }
        if (filled($client->logo_path)) {
            Storage::disk('public')->delete($client->logo_path);
        }
        $client->update([
            'logo_path' => $file->store('client-logos/'.$client->id, 'public'),
        ]);
    }

    /**
     * @return array{id: int, client_id: int, client_name: string, calendar_id: int|null, kind: string, color: string|null, scheduled_at: string|null, caption: string|null, images: list<array{id: int, name: string|null}>, has_image: bool, image_name: string|null, logo_url: string|null}
     */
    private function payload(ClientReportSlot $slot): array
    {
        $this->adoptLegacyImage($slot);
        $slot->loadMissing(['client', 'calendar', 'images']);
        $client = $slot->client;
        $images = $slot->images->map(fn (ClientReportSlotImage $image): array => [
            'id' => $image->id,
            'name' => $image->name,
        ])->values()->all();

        return [
            'id' => $slot->id,
            'client_id' => $slot->client_id,
            'client_name' => trim((string) ($client?->company_name ?: $client?->name)),
            'calendar_id' => $slot->report_calendar_id,
            'kind' => $slot->kind === 'image' ? 'carousel' : $slot->kind,
            'color' => $slot->color,
            'scheduled_at' => $slot->scheduled_at?->timezone('Asia/Damascus')->toIso8601String(),
            'caption' => $slot->caption,
            'images' => $images,
            'has_image' => $images !== [],
            'image_name' => $images[0]['name'] ?? null,
            'logo_url' => $this->logoUrl($client),
        ];
    }

    /** A slot saved before several pictures keeps its one file as the first picture. */
    private function adoptLegacyImage(ClientReportSlot $slot): void
    {
        if ($slot->images()->exists() || blank($slot->image_path)) {
            return;
        }
        $slot->images()->create([
            'path' => $slot->image_path,
            'name' => $slot->image_name,
            'position' => 0,
        ]);
        $slot->update(['image_path' => null, 'image_name' => null]);
        $slot->unsetRelation('images');
    }

    private function logoUrl(?Client $client): ?string
    {
        if ($client === null) {
            return null;
        }
        if (filled($client->logo_path)) {
            return Storage::disk('public')->url($client->logo_path);
        }
        $names = array_values(array_filter([
            trim((string) $client->company_name),
            trim((string) $client->name),
        ]));
        if ($names === []) {
            return null;
        }
        $showcase = ShowcaseClient::query()
            ->whereNotNull('logo_path')
            ->whereIn('name', $names)
            ->first();

        return filled($showcase?->logo_path) ? Storage::disk('public')->url($showcase->logo_path) : null;
    }
}
