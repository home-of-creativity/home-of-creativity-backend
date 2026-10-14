<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\User;
use App\Support\CommentAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Team comments on reports, social posts, calendar events, and photography bookings.
 * Each call checks the thing under the comments with `CommentAccess`.
 */
class CommentController extends Controller
{
    /** Comment files are private: they are read through the API with the thread's access. */
    private const DISK = 'local';

    public function __construct(private CommentAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        [$user, $type, $id, $calendarId] = $this->subject($request);

        $rows = Comment::query()
            ->onSubject($type, $id, $calendarId)
            ->with('user:id,name')
            ->oldest('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (Comment $comment): array => $this->row($comment, $user))->all()]);
    }

    /** Comment counts for several things of one type, for the badges on lists. */
    public function counts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', Rule::in(Comment::SUBJECTS)],
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['string', 'max:1024'],
            'calendar_id' => ['nullable', 'string', 'max:255'],
        ]);
        /** @var User $user */
        $user = $request->user();

        $counts = [];
        foreach (array_unique($validated['ids']) as $id) {
            $calendarId = $validated['calendar_id'] ?? null;
            if (! $this->access->allows($user, $validated['subject'], $id, $calendarId)) {
                continue;
            }
            $count = Comment::query()->onSubject($validated['subject'], $id, $calendarId)->count();
            if ($count > 0) {
                $counts[$id] = $count;
            }
        }

        return response()->json(['data' => (object) $counts]);
    }

    public function store(Request $request): JsonResponse
    {
        [$user, $type, $id, $calendarId] = $this->subject($request);
        $validated = $request->validate([
            'body' => ['nullable', 'required_without:attachment', 'string', 'max:4000'],
            'parent_id' => ['nullable', 'integer'],
            // Browsers record voice notes as WebM or Ogg, which can read as video.
            'attachment' => ['nullable', 'file', 'max:15360', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/x-m4a,audio/aac,audio/wav,audio/x-wav,video/webm,video/ogg'],
        ]);

        $parentId = null;
        if (filled($validated['parent_id'] ?? null)) {
            $parent = Comment::query()->onSubject($type, $id, $calendarId)->find((int) $validated['parent_id']);
            abort_if($parent === null, 422, 'التعليق الذي ترد عليه غير موجود.');
            // One level of replies: a reply to a reply joins the same thread.
            $parentId = $parent->parent_id ?? $parent->id;
        }

        $comment = Comment::query()->create([
            'subject_type' => $type,
            'subject_id' => $id,
            'subject_key' => Comment::keyFor($id, $calendarId),
            'calendar_id' => $calendarId,
            'user_id' => $user->id,
            'parent_id' => $parentId,
            'body' => trim((string) ($validated['body'] ?? '')),
        ]);
        $file = $request->file('attachment');
        if ($file instanceof UploadedFile) {
            $mime = (string) $file->getMimeType();
            $comment->forceFill([
                'attachment_kind' => str_starts_with($mime, 'image/') ? 'image' : 'audio',
                'attachment_path' => $file->store('comments/'.$comment->id, self::DISK),
                'attachment_name' => mb_substr($file->getClientOriginalName() ?: 'file', 0, 190),
                'attachment_mime' => str_starts_with($mime, 'video/') ? 'audio/'.substr($mime, 6) : $mime,
                'attachment_size' => (int) $file->getSize(),
            ])->save();
        }

        return response()->json(['data' => $this->row($comment->load('user:id,name'), $user), 'message' => 'أُضيف التعليق.'], 201);
    }

    public function update(Request $request, Comment $comment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->allows($user, $comment->subject_type, $comment->subject_id, $comment->calendar_id), 403);
        abort_unless($comment->user_id === $user->id, 403, 'تعدّل تعليقاتك فقط.');
        $validated = $request->validate(['body' => [filled($comment->attachment_path) ? 'nullable' : 'required', 'string', 'max:4000']]);

        $comment->forceFill(['body' => trim((string) ($validated['body'] ?? '')), 'edited_at' => now()])->save();

        return response()->json(['data' => $this->row($comment->load('user:id,name'), $user), 'message' => 'عُدّل التعليق.']);
    }

    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->allows($user, $comment->subject_type, $comment->subject_id, $comment->calendar_id), 403);
        abort_unless($this->access->canDelete($user, (int) $comment->user_id), 403, 'تحذف تعليقاتك فقط.');

        // Replies go with the thread start, and their files with them.
        foreach ([$comment->id, ...$comment->replies()->pluck('id')->all()] as $id) {
            Storage::disk(self::DISK)->deleteDirectory('comments/'.$id);
        }
        $comment->delete();

        return response()->json(['data' => null, 'message' => 'حُذف التعليق.']);
    }

    /** The picture or voice note of a comment, for whoever may read that thread. */
    public function attachment(Request $request, Comment $comment): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->allows($user, $comment->subject_type, $comment->subject_id, $comment->calendar_id), 403);
        abort_unless(filled($comment->attachment_path) && Storage::disk(self::DISK)->exists((string) $comment->attachment_path), 404);

        return Storage::disk(self::DISK)->response(
            (string) $comment->attachment_path,
            (string) $comment->attachment_name,
            ['Content-Type' => (string) $comment->attachment_mime],
        );
    }

    /**
     * @return array{0: User, 1: string, 2: string, 3: string|null}
     */
    private function subject(Request $request): array
    {
        $validated = $request->validate([
            'subject' => ['required', Rule::in(Comment::SUBJECTS)],
            'id' => ['required', 'string', 'max:1024'],
            'calendar_id' => ['nullable', 'required_if:subject,calendar_event', 'string', 'max:255'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $calendarId = $validated['subject'] === 'calendar_event' ? (string) $validated['calendar_id'] : null;
        abort_unless($this->access->allows($user, $validated['subject'], $validated['id'], $calendarId), 403, 'لا تملك صلاحية التعليق هنا.');

        return [$user, $validated['subject'], $validated['id'], $calendarId];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Comment $comment, User $user): array
    {
        return [
            'id' => $comment->id,
            'parent_id' => $comment->parent_id,
            'body' => (string) $comment->body,
            'attachment' => filled($comment->attachment_path) ? [
                'kind' => $comment->attachment_kind,
                'name' => $comment->attachment_name,
                'mime' => $comment->attachment_mime,
                'size' => (int) $comment->attachment_size,
            ] : null,
            'user' => $comment->user ? ['id' => $comment->user->id, 'name' => $comment->user->name] : null,
            'created_at' => $comment->created_at?->toIso8601String(),
            'edited_at' => $comment->edited_at?->toIso8601String(),
            'can_edit' => $comment->user_id === $user->id,
            'can_delete' => $this->access->canDelete($user, (int) $comment->user_id),
        ];
    }
}
