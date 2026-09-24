<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SocialAbility;
use App\Enums\SocialActivityAction;
use App\Enums\SocialPlacement;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPublishStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSocialPostRequest;
use App\Http\Requests\UpdateSocialPostRequest;
use App\Http\Resources\SocialPostResource;
use App\Jobs\PublishSocialPostJob;
use App\Models\SocialInboxItem;
use App\Models\SocialPost;
use App\Models\SocialPostAccount;
use App\Models\SocialPostMedia;
use App\Services\SocialActivityLogger;
use App\Services\SocialPageAccess;
use App\Services\SocialPublishedPostSync;
use App\Services\SocialPublisher;
use App\Services\SocialSchedulePublisher;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SocialPostController extends Controller
{
    public function __construct(
        private SocialActivityLogger $logger,
        private SocialPublisher $publisher,
        private SocialPublishedPostSync $publishedSync,
        private SocialSchedulePublisher $schedulePublisher,
        private SocialPageAccess $pages,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->canViewPosts($request->user()), 403);

        $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(SocialPostStatus::values())],
            'account_id' => ['sometimes', 'nullable', 'integer', 'exists:social_accounts,id'],
            'placement' => ['sometimes', 'nullable', Rule::in(SocialPlacement::values())],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $this->schedulePublisher->dispatchDue();
        $this->publishedSync->prune(
            $request->filled('account_id') ? $request->integer('account_id') : null,
        );

        $query = SocialPost::query()
            ->with(['accounts', 'media', 'creator:id,name', 'updater:id,name', 'approver:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('account_id'), function ($q) use ($request) {
                $q->whereHas('accounts', fn ($accounts) => $accounts->where('social_accounts.id', $request->integer('account_id')));
            })
            ->when($request->filled('placement'), fn ($q) => $q->where('placement', $request->string('placement')))
            ->when(true, function ($q) use ($request) {
                $allowed = $this->pages->allowedAccountIds($request->user());
                if ($allowed === null) {
                    return;
                }
                if ($allowed === []) {
                    $q->whereRaw('0 = 1');

                    return;
                }
                $q->whereDoesntHave('accounts', fn ($accounts) => $accounts->whereNotIn('social_accounts.id', $allowed));
            })
            ->when($request->filled('from') && $request->filled('to'), function ($q) use ($request) {
                $from = $request->date('from')->startOfDay();
                $to = $request->date('to')->endOfDay();
                $q->where(function ($inner) use ($from, $to) {
                    $inner->whereBetween('scheduled_at', [$from, $to])
                        ->orWhereBetween('published_at', [$from, $to])
                        ->orWhere(function ($drafts) use ($from, $to) {
                            $drafts->where('status', SocialPostStatus::Draft)
                                ->whereBetween('created_at', [$from, $to]);
                        });
                });
            });

        return SocialPostResource::collection(
            $query->latest()->paginate($request->integer('per_page', 20))
        )->additional(['message' => 'ok']);
    }

    public function show(SocialPost $socialPost): SocialPostResource
    {
        abort_unless($this->canViewPosts(request()->user()), 403);
        $this->assertPostPages(request()->user(), $socialPost);

        $socialPost->load([
            'accounts',
            'media',
            'creator:id,name',
            'updater:id,name',
            'approver:id,name',
            'activities.user:id,name',
        ]);

        return SocialPostResource::make($socialPost)->additional(['message' => 'ok']);
    }

    public function store(StoreSocialPostRequest $request): JsonResponse
    {
        $this->pages->assertAccounts($request->user(), $request->validated('account_ids'));
        $post = SocialPost::query()->create([
            'body' => $request->validated('body') ?? '',
            'placement' => $request->validated('placement') ?? SocialPlacement::Feed->value,
            'status' => SocialPostStatus::Draft,
            'scheduled_at' => $this->scheduledAt($request->input('scheduled_at')),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $this->syncAccounts($post, $request->validated('account_ids'));
        $this->storeMedia($post, $request->file('media', []));
        $this->applyIntent($post, $request->input('intent', 'draft'), $request->user());

        $this->logger->log($request->user(), SocialActivityAction::Created, $post, [
            'intent' => $request->input('intent', 'draft'),
        ]);

        return SocialPostResource::make($this->fresh($post))
            ->additional(['message' => 'Created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateSocialPostRequest $request, SocialPost $socialPost): SocialPostResource
    {
        abort_unless($socialPost->isEditable(), 422, 'This post can no longer be edited.');
        $this->assertPostPages($request->user(), $socialPost);
        if ($request->has('account_ids')) {
            $this->pages->assertAccounts($request->user(), $request->validated('account_ids'));
        }

        $wasPublished = $socialPost->status === SocialPostStatus::Published;
        $previousTargets = $socialPost->targets()->with('account')->get();
        $mediaChanged = $request->hasFile('media') || (is_array($request->input('remove_media_ids')) && $request->input('remove_media_ids') !== []);

        $data = $request->safe()->only(['body', 'placement', 'scheduled_at']);
        $data['updated_by'] = $request->user()->id;
        if ($request->exists('scheduled_at')) {
            $data['scheduled_at'] = $this->scheduledAt($request->input('scheduled_at'));
        }

        $socialPost->fill($data)->save();

        if ($request->has('account_ids')) {
            $this->syncAccounts($socialPost, $request->validated('account_ids'));
        }

        $this->removeMedia($socialPost, $request->input('remove_media_ids', []));
        $this->storeMedia($socialPost, $request->file('media', []));
        $socialPost->unsetRelation('media');
        $socialPost->load(['accounts', 'media', 'targets.account']);

        if ($wasPublished) {
            $this->syncPublishedPost($socialPost, $previousTargets, $mediaChanged);
        } else {
            $this->applyIntent($socialPost, $request->input('intent', 'draft'), $request->user());
        }

        $this->logger->log($request->user(), SocialActivityAction::Updated, $socialPost);

        return SocialPostResource::make($this->fresh($socialPost))
            ->additional(['message' => 'Updated.']);
    }

    public function destroy(SocialPost $socialPost)
    {
        abort_unless(request()->user()?->canSocial(SocialAbility::Create), 403);
        $this->assertPostPages(request()->user(), $socialPost);
        abort_unless($socialPost->isDeletable(), 422, 'This post can no longer be deleted.');

        $failures = [];
        foreach ($socialPost->targets()->with('account')->get() as $target) {
            if (! filled($target->external_id) || ! $target->account) {
                continue;
            }

            $result = $this->publisher->deleteLive($target->account, (string) $target->external_id);
            if (! $result['ok'] && is_string($result['error'])) {
                $failures[] = $target->account->name.': '.$result['error'];

                continue;
            }

            SocialInboxItem::deleteCommentsForSource($target->account->id, (string) $target->external_id);
        }

        abort_if($failures !== [], 422, implode(' | ', $failures));

        $socialPost->media->each(function (SocialPostMedia $media): void {
            Storage::disk('public')->delete($media->path);
        });
        $socialPost->delete();

        return response()->json([
            'data' => null,
            'message' => 'Deleted.',
        ]);
    }

    public function approve(SocialPost $socialPost): SocialPostResource
    {
        abort_unless(request()->user()?->canSocial(SocialAbility::Approve), 403);
        $this->assertPostPages(request()->user(), $socialPost);
        abort_unless($socialPost->isEditable(), 422, 'This post cannot be approved.');

        $this->markApproved($socialPost, request()->user());

        if ($socialPost->scheduled_at && $socialPost->scheduled_at->isFuture()) {
            $socialPost->forceFill(['status' => SocialPostStatus::Scheduled])->save();
            $this->logger->log(request()->user(), SocialActivityAction::Scheduled, $socialPost);
        } else {
            $this->dispatchPublish($socialPost, request()->user(), immediately: true);
        }

        $this->logger->log(request()->user(), SocialActivityAction::Approved, $socialPost);

        return SocialPostResource::make($this->fresh($socialPost))
            ->additional(['message' => 'Approved.']);
    }

    public function publish(SocialPost $socialPost): SocialPostResource
    {
        abort_unless(request()->user()?->canSocial(SocialAbility::Approve), 403);
        $this->assertPostPages(request()->user(), $socialPost);
        abort_unless($socialPost->canRetryPublish(), 422, 'This post cannot be published.');

        $this->markApproved($socialPost, request()->user());
        $this->dispatchPublish($socialPost, request()->user(), immediately: true);

        return SocialPostResource::make($this->fresh($socialPost))
            ->additional(['message' => 'Publishing.']);
    }

    private function canViewPosts(mixed $user): bool
    {
        return $user?->canSocial(SocialAbility::Create)
            || $user?->canSocial(SocialAbility::Approve)
            || $user?->canSocial(SocialAbility::Engage)
            || $user?->canSocial(SocialAbility::Accounts);
    }

    private function assertPostPages(mixed $user, SocialPost $post): void
    {
        if (! $user) {
            abort(403);
        }

        $ids = $post->accounts()->pluck('social_accounts.id')->map(fn ($id): int => (int) $id)->all();
        $this->pages->assertAccounts($user, $ids);
    }

    private function applyIntent(SocialPost $post, string $intent, mixed $user): void
    {
        if ($intent === 'draft') {
            $post->forceFill(['status' => SocialPostStatus::Draft])->save();

            return;
        }

        if ($intent === 'schedule') {
            abort_unless($post->scheduled_at !== null, 422, 'A schedule time is required.');

            if ($post->scheduled_at->lte(now())) {
                $this->dispatchPublish($post, $user, immediately: true);

                return;
            }

            $post->forceFill(['status' => SocialPostStatus::Scheduled])->save();
            $this->logger->log($user, SocialActivityAction::Scheduled, $post);

            return;
        }

        if ($intent === 'publish') {
            if ($user->canSocial(SocialAbility::Approve)) {
                $this->markApproved($post, $user);
                $this->dispatchPublish($post, $user, immediately: true);
            } else {
                $post->forceFill(['status' => SocialPostStatus::Draft])->save();
            }
        }
    }

    private function markApproved(SocialPost $post, mixed $user): void
    {
        $post->forceFill([
            'approved_by' => $user->id,
            'approved_at' => now(),
        ])->save();
    }

    private function dispatchPublish(SocialPost $post, mixed $user, bool $immediately = false): void
    {
        $post->forceFill([
            'status' => SocialPostStatus::Publishing,
            'last_error' => null,
        ])->save();

        $this->logger->log($user, SocialActivityAction::Publishing, $post);
        PublishSocialPostJob::dispatchFor($post->id, $immediately);
    }

    private function scheduledAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value, (string) config('services.social.timezone', 'Asia/Damascus'))
            ->utc();
    }

    /**
     * @param  Collection<int, SocialPostAccount>  $previousTargets
     */
    private function syncPublishedPost(SocialPost $post, Collection $previousTargets, bool $mediaChanged): void
    {
        $keepIds = $post->accounts->pluck('id');
        $failures = [];

        foreach ($previousTargets as $target) {
            if ($keepIds->contains($target->social_account_id) || ! filled($target->external_id) || ! $target->account) {
                continue;
            }

            $result = $this->publisher->deleteLive($target->account, (string) $target->external_id);
            if (! $result['ok'] && is_string($result['error'])) {
                $failures[] = $target->account->name.': '.$result['error'];
            }
        }

        foreach ($post->targets()->with('account')->get() as $target) {
            $account = $target->account;
            if (! $account) {
                continue;
            }

            $externalId = filled($target->external_id) ? (string) $target->external_id : null;
            if ($externalId && $mediaChanged) {
                $this->publisher->deleteLive($account, $externalId);
                $result = $this->publisher->publish($account, $post);
            } elseif ($externalId) {
                $result = $this->publisher->updateLive($account, $externalId, $post);
                if (! $result['ok']) {
                    $this->publisher->deleteLive($account, $externalId);
                    $result = $this->publisher->publish($account, $post);
                }
            } else {
                $result = $this->publisher->publish($account, $post);
            }

            $target->forceFill([
                'status' => $result['ok'] ? SocialPublishStatus::Published : SocialPublishStatus::Failed,
                'external_id' => $result['external_id'] ?? $externalId,
                'published_at' => $result['ok'] ? ($target->published_at ?? now()) : $target->published_at,
                'last_error' => $result['error'],
            ])->save();

            if (! $result['ok'] && is_string($result['error'])) {
                $failures[] = $account->name.': '.$result['error'];
            }
        }

        $post->forceFill([
            'status' => $failures === [] ? SocialPostStatus::Published : SocialPostStatus::Failed,
            'last_error' => $failures === [] ? null : implode(' | ', $failures),
        ])->save();
    }

    /**
     * @param  list<int>  $accountIds
     */
    private function syncAccounts(SocialPost $post, array $accountIds): void
    {
        $existing = $post->targets()->get()->keyBy('social_account_id');
        $payload = [];
        foreach ($accountIds as $id) {
            $current = $existing->get($id);
            $payload[$id] = [
                'status' => $current?->status instanceof SocialPublishStatus
                    ? $current->status->value
                    : ($current?->status ?? SocialPublishStatus::Pending->value),
                'external_id' => $current?->external_id,
                'published_at' => $current?->published_at,
                'last_error' => $current?->last_error,
            ];
        }
        $post->accounts()->sync($payload);
    }

    /**
     * @param  array<int, UploadedFile>|UploadedFile|null  $files
     */
    private function storeMedia(SocialPost $post, mixed $files): void
    {
        $files = is_array($files) ? $files : ($files ? [$files] : []);
        $order = (int) $post->media()->max('sort_order');

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $order++;
            $mime = (string) $file->getMimeType();
            $post->media()->create([
                'path' => $file->store('social/posts', 'public'),
                'original_name' => $file->getClientOriginalName(),
                'mime' => $mime,
                'kind' => str_starts_with($mime, 'video/') ? 'video' : 'image',
                'sort_order' => $order,
            ]);
        }
    }

    /**
     * @param  list<int>|mixed  $ids
     */
    private function removeMedia(SocialPost $post, mixed $ids): void
    {
        if (! is_array($ids) || $ids === []) {
            return;
        }

        $post->media()->whereIn('id', $ids)->get()->each(function (SocialPostMedia $media): void {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        });
    }

    private function fresh(SocialPost $post): SocialPost
    {
        return $post->fresh([
            'accounts',
            'media',
            'creator:id,name',
            'updater:id,name',
            'approver:id,name',
            'activities.user:id,name',
        ]);
    }
}
