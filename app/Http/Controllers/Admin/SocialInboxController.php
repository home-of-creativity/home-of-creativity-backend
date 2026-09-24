<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SocialActivityAction;
use App\Enums\SocialInboxKind;
use App\Enums\StaffAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReplySocialInboxRequest;
use App\Http\Resources\SocialInboxItemResource;
use App\Models\SocialInboxItem;
use App\Services\SocialActivityLogger;
use App\Services\SocialInboxSync;
use App\Services\SocialPageAccess;
use App\Services\SocialPublisher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SocialInboxController extends Controller
{
    public function __construct(
        private SocialActivityLogger $logger,
        private SocialPublisher $publisher,
        private SocialInboxSync $inboxSync,
        private SocialPageAccess $pages,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $canComments = $user?->canAbility(StaffAbility::SocialEngage) ?? false;
        $canMessages = $user?->canAbility(StaffAbility::SocialMessages) ?? false;
        abort_unless($canComments || $canMessages, 403);

        $request->validate([
            'kind' => ['sometimes', 'nullable', Rule::in(SocialInboxKind::values())],
            'account_id' => ['sometimes', 'nullable', 'integer', 'exists:social_accounts,id'],
        ]);

        $this->inboxSync->syncAll();

        $items = SocialInboxItem::query()
            ->with(['account:id,platform,name', 'replies.user:id,name', 'sourcePost:id,body,placement'])
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when(! $canComments, fn ($q) => $q->where('kind', SocialInboxKind::Message))
            ->when(! $canMessages, fn ($q) => $q->where('kind', SocialInboxKind::Comment))
            ->when($request->filled('account_id'), fn ($q) => $q->where('social_account_id', $request->integer('account_id')))
            ->when(true, function ($q) use ($user) {
                $allowed = $user ? $this->pages->allowedAccountIds($user) : [];
                if ($allowed === null) {
                    return;
                }
                if ($allowed === []) {
                    $q->whereRaw('0 = 1');

                    return;
                }
                $q->whereIn('social_account_id', $allowed);
            })
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(30);

        return SocialInboxItemResource::collection($items)->additional([
            'message' => 'ok',
            'sync_error' => $this->inboxSync->lastError,
        ]);
    }

    public function reply(ReplySocialInboxRequest $request, SocialInboxItem $socialInboxItem): SocialInboxItemResource
    {
        $account = $socialInboxItem->account;
        abort_unless($account !== null, 422, 'Inbox item has no account.');

        $reply = $socialInboxItem->replies()->create([
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        $result = $this->publisher->reply(
            $account,
            $socialInboxItem->external_id,
            $reply->body,
            $socialInboxItem->kind->value,
            $socialInboxItem->author_handle,
        );

        $reply->forceFill([
            'external_id' => $result['external_id'],
            'sent_at' => $result['ok'] ? now() : null,
            'last_error' => $result['error'],
        ])->save();

        if ($result['ok']) {
            $socialInboxItem->forceFill(['is_replied' => true])->save();
        }

        $this->logger->log($request->user(), SocialActivityAction::Replied, $socialInboxItem, [
            'ok' => $result['ok'],
        ]);

        if (! $result['ok']) {
            abort(422, $result['error'] ?? 'Reply failed.');
        }

        return SocialInboxItemResource::make(
            $socialInboxItem->fresh(['account:id,platform,name', 'replies.user:id,name', 'sourcePost:id,body,placement'])
        )->additional(['message' => 'Replied.']);
    }

    public function sync()
    {
        abort_unless(
            request()->user()?->canAbility(StaffAbility::SocialEngage)
            || request()->user()?->canAbility(StaffAbility::SocialMessages),
            403,
        );

        $imported = $this->inboxSync->syncAll();

        return response()->json([
            'data' => ['imported' => $imported],
            'message' => 'Synced.',
            'sync_error' => $this->inboxSync->lastError,
        ]);
    }
}
