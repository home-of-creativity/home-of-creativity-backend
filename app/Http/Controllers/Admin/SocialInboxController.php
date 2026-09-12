<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SocialAbility;
use App\Enums\SocialActivityAction;
use App\Enums\SocialInboxKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReplySocialInboxRequest;
use App\Http\Resources\SocialInboxItemResource;
use App\Models\SocialInboxItem;
use App\Services\SocialActivityLogger;
use App\Services\SocialInboxSync;
use App\Services\SocialPublisher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SocialInboxController extends Controller
{
    public function __construct(
        private SocialActivityLogger $logger,
        private SocialPublisher $publisher,
        private SocialInboxSync $inboxSync,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()?->canSocial(SocialAbility::Engage), 403);

        $request->validate([
            'kind' => ['sometimes', 'nullable', Rule::in(SocialInboxKind::values())],
            'account_id' => ['sometimes', 'nullable', 'integer', 'exists:social_accounts,id'],
        ]);

        $this->inboxSync->syncAll();

        $items = SocialInboxItem::query()
            ->with(['account:id,platform,name', 'replies.user:id,name'])
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when($request->filled('account_id'), fn ($q) => $q->where('social_account_id', $request->integer('account_id')))
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
            $socialInboxItem->fresh(['account:id,platform,name', 'replies.user:id,name'])
        )->additional(['message' => 'Replied.']);
    }

    public function sync()
    {
        abort_unless(request()->user()?->canSocial(SocialAbility::Engage), 403);

        $imported = $this->inboxSync->syncAll();

        return response()->json([
            'data' => ['imported' => $imported],
            'message' => 'Synced.',
            'sync_error' => $this->inboxSync->lastError,
        ]);
    }
}
