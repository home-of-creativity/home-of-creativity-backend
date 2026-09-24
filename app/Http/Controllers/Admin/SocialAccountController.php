<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SocialAbility;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialActivityAction;
use App\Enums\StaffAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSocialAccountRequest;
use App\Http\Requests\UpdateSocialAccountRequest;
use App\Http\Resources\SocialAccountResource;
use App\Models\SocialAccount;
use App\Services\LinkedInGraph;
use App\Services\SocialAccountSync;
use App\Services\SocialActivityLogger;
use App\Services\SocialPageAccess;
use App\Services\ThreadsGraph;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class SocialAccountController extends Controller
{
    public function __construct(
        private SocialActivityLogger $logger,
        private SocialAccountSync $sync,
        private SocialPageAccess $pages,
    ) {}

    public function index()
    {
        $user = request()->user();
        abort_unless(
            $user?->canAbility(StaffAbility::SocialAccounts)
            || $user?->canAbility(StaffAbility::SocialContent)
            || $user?->canAbility(StaffAbility::SocialEngage)
            || $user?->canAbility(StaffAbility::SocialMessages)
            || $user?->canAbility(StaffAbility::SocialApprove),
            403,
        );

        $connectedBy = request()->user()?->id;

        try {
            if (app()->runningUnitTests() || PHP_SAPI !== 'cli-server') {
                $meta = app()->runningUnitTests()
                    ? $this->syncFacebookAccounts($connectedBy)
                    : Cache::remember('social:facebook-sync', 60, fn (): array => $this->syncFacebookAccounts($connectedBy));

                $this->sync->facebookPagesFound = (int) ($meta['facebook_pages_found'] ?? 0);
                $this->sync->lastError = $meta['facebook_error'] ?? $this->sync->lastError;
                $this->sync->threadsError = $meta['threads_error'] ?? $this->sync->threadsError;
                $this->sync->linkedinError = $meta['linkedin_error'] ?? $this->sync->linkedinError;
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->sync->lastError = $this->sync->lastError ?: 'facebook_sync_failed';
        }

        $accounts = SocialAccount::query()
            ->with('connector:id,name')
            ->whereIn('connection_status', [
                SocialAccountStatus::Connected,
                SocialAccountStatus::Error,
            ])
            ->orderBy('platform')
            ->orderBy('name');

        $allowed = $user ? $this->pages->allowedAccountIds($user) : [];
        if ($allowed === []) {
            $accounts->whereRaw('0 = 1');
        } elseif (is_array($allowed)) {
            $accounts->whereIn('id', $allowed);
        }

        return SocialAccountResource::collection(
            $accounts->get()
        )->additional([
            'message' => 'ok',
            'facebook_configured' => $this->sync->configured(),
            'facebook_error' => $this->sync->lastError,
            'facebook_pages_found' => $this->sync->facebookPagesFound,
            'threads_configured' => $this->sync->threadsConfigured(),
            'threads_error' => $this->sync->threadsError,
            'threads_oauth_configured' => ThreadsGraph::oauthConfigured(),
            'threads_redirect_uri' => ThreadsGraph::redirectUri(),
            'linkedin_oauth_configured' => LinkedInGraph::oauthConfigured(),
            'linkedin_redirect_uri' => LinkedInGraph::redirectUri(),
            'linkedin_error' => $this->sync->linkedinError,
        ]);
    }

    /**
     * @return array{facebook_pages_found: int, facebook_error: ?string, threads_error: ?string, linkedin_error: ?string}
     */
    private function syncFacebookAccounts(?int $connectedBy): array
    {
        $this->sync->syncFromFacebook($connectedBy);

        return [
            'facebook_pages_found' => $this->sync->facebookPagesFound,
            'facebook_error' => $this->sync->lastError,
            'threads_error' => $this->sync->threadsError,
            'linkedin_error' => $this->sync->linkedinError,
        ];
    }

    public function store(StoreSocialAccountRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['access_token', 'refresh_token']);
        $data['is_active'] = $data['is_active'] ?? true;
        $data['connected_by'] = $request->user()->id;
        $data['connection_status'] = $request->filled('access_token')
            ? SocialAccountStatus::Connected
            : SocialAccountStatus::Pending;

        $account = new SocialAccount($data);
        if ($request->filled('access_token')) {
            $account->access_token = $request->input('access_token');
        }
        if ($request->filled('refresh_token')) {
            $account->refresh_token = $request->input('refresh_token');
        }
        $account->save();

        $this->logger->log($request->user(), SocialActivityAction::AccountConnected, $account, [
            'platform' => $account->platform->value,
        ]);

        return SocialAccountResource::make($account->load('connector:id,name'))
            ->additional(['message' => 'Created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateSocialAccountRequest $request, SocialAccount $socialAccount): SocialAccountResource
    {
        $this->pages->assertAccounts($request->user(), [$socialAccount->id]);
        $data = $request->safe()->except(['access_token', 'refresh_token']);

        if ($request->filled('access_token')) {
            $socialAccount->access_token = $request->input('access_token');
            $data['connection_status'] = SocialAccountStatus::Connected;
            $data['last_error'] = null;
        }

        if ($request->filled('refresh_token')) {
            $socialAccount->refresh_token = $request->input('refresh_token');
        }

        $socialAccount->fill($data)->save();

        $this->logger->log($request->user(), SocialActivityAction::AccountUpdated, $socialAccount);

        return SocialAccountResource::make($socialAccount->fresh()->load('connector:id,name'))
            ->additional(['message' => 'Updated.']);
    }

    public function toggle(SocialAccount $socialAccount): SocialAccountResource
    {
        abort_unless(request()->user()?->canSocial(SocialAbility::Accounts), 403);
        $this->pages->assertAccounts(request()->user(), [$socialAccount->id]);

        $socialAccount->forceFill([
            'is_active' => ! $socialAccount->is_active,
        ])->save();

        $this->logger->log(request()->user(), SocialActivityAction::AccountToggled, $socialAccount, [
            'is_active' => $socialAccount->is_active,
        ]);

        return SocialAccountResource::make($socialAccount->fresh()->load('connector:id,name'))
            ->additional(['message' => 'Updated.']);
    }

    public function destroy(SocialAccount $socialAccount)
    {
        abort_unless(request()->user()?->canSocial(SocialAbility::Accounts), 403);
        $this->pages->assertAccounts(request()->user(), [$socialAccount->id]);

        $socialAccount->forceFill([
            'is_active' => false,
            'connection_status' => SocialAccountStatus::Disconnected,
            'last_error' => 'user_disconnected',
        ])->save();

        $this->logger->log(request()->user(), SocialActivityAction::AccountToggled, $socialAccount, [
            'disconnected' => true,
        ]);

        return response()->json([
            'data' => null,
            'message' => 'Deleted.',
        ]);
    }
}
