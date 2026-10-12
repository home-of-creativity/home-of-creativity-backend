<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ComplaintTickets;
use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\ComplaintMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplaintController extends Controller
{
    public function __construct(private ComplaintTickets $tickets) {}

    public function index(): JsonResponse
    {
        $rows = Complaint::query()
            ->with('client:id,name,company_name,phone,email')
            ->withCount('messages')
            ->withMax('messages', 'created_at')
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Complaint $row): array => $this->row($row))->values(),
        ]);
    }

    public function show(Complaint $complaint): JsonResponse
    {
        return response()->json(['data' => $this->detail($complaint)]);
    }

    public function update(Request $request, Complaint $complaint): JsonResponse
    {
        $data = $request->validate([
            // `reviewed` is the old name of `resolved`.
            'status' => ['required', Rule::in([...Complaint::STATUSES, 'reviewed'])],
        ]);
        $status = $data['status'] === 'reviewed' ? Complaint::RESOLVED : $data['status'];
        $this->tickets->setStatus($complaint, $status);

        return response()->json(['data' => $this->detail($complaint), 'message' => 'حُفظت حالة الشكوى.']);
    }

    public function openTicket(Complaint $complaint): JsonResponse
    {
        $this->tickets->open($complaint);

        return response()->json(['data' => $this->detail($complaint), 'message' => 'فُتحت التذكرة '.$complaint->ticket_number.'.']);
    }

    public function reply(Request $request, Complaint $complaint): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'channel' => ['required', 'in:bot,email,note'],
            'resolve' => ['boolean'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $message = $this->tickets->reply($complaint, $user, $data['body'], $data['channel'], (bool) ($data['resolve'] ?? false));

        $text = match (true) {
            $data['channel'] === 'note' => 'حُفظت الملاحظة على التذكرة.',
            ! $message->delivered => 'حُفظ الرد، لكنه لم يصل للعميل. جرّب قناة أخرى.',
            $data['channel'] === 'email' => 'وصل الرد للعميل بالإيميل.',
            default => 'وصل الرد للعميل على البوت.',
        };

        return response()->json(['data' => $this->detail($complaint), 'message' => $text], $message->delivered ? 201 : 202);
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

    /** @return array<string, mixed> */
    private function row(Complaint $row): array
    {
        $last = $row->getAttribute('messages_max_created_at');

        return [
            'id' => $row->id,
            'ticket_number' => $row->ticket_number,
            'title' => $row->title,
            'description' => $row->description,
            'status' => $row->status === 'reviewed' ? Complaint::RESOLVED : $row->status,
            'has_image' => filled($row->image_path),
            'has_audio' => filled($row->audio_path),
            'client_name' => $row->client?->name,
            'company_name' => $row->client?->company_name,
            'phone' => $row->client?->phone,
            'messages_count' => (int) ($row->messages_count ?? 0),
            'created_at' => $row->created_at?->toIso8601String(),
            'last_activity_at' => $last ? \Illuminate\Support\Carbon::parse($last)->toIso8601String() : $row->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Complaint $complaint): array
    {
        $complaint->refresh()->load(['client:id,name,company_name,phone,email,telegram_user_id', 'messages.user:id,name']);
        $complaint->loadCount('messages');

        return [
            ...$this->row($complaint),
            'ticket_opened_at' => $complaint->ticket_opened_at?->toIso8601String(),
            'resolved_at' => $complaint->status === Complaint::RESOLVED ? $complaint->reviewed_at?->toIso8601String() : null,
            'channels' => $this->tickets->channels($complaint),
            'messages' => $complaint->messages->sortBy('id')->values()->map(fn (ComplaintMessage $message): array => [
                'id' => $message->id,
                'author' => $message->author,
                'author_name' => $message->author === ComplaintMessage::STAFF ? $message->user?->name : ($complaint->client?->name ?? null),
                'channel' => $message->channel,
                'body' => $message->body,
                'delivered' => $message->delivered,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
