<?php

namespace App\Http\Controllers;

use App\Enums\SocialAbility;
use App\Enums\SocialActivityAction;
use App\Models\User;
use App\Services\SocialAccountSync;
use App\Services\SocialActivityLogger;
use App\Services\ThreadsGraph;
use App\Services\ThreadsOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ThreadsOAuthController extends Controller
{
    public function __construct(
        private ThreadsOAuth $oauth,
        private SocialAccountSync $sync,
        private SocialActivityLogger $logger,
    ) {}

    public function redirect(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canSocial(SocialAbility::Accounts), 403);

        if (! ThreadsGraph::oauthConfigured()) {
            return response()->json([
                'data' => null,
                'message' => 'Threads OAuth is not configured.',
            ], 422);
        }

        $userId = (int) $request->user()->id;
        $state = $this->oauth->rememberState($userId);

        return response()->json([
            'data' => [
                'authorize_url' => $this->oauth->authorizeUrl($state),
                'redirect_uri' => ThreadsGraph::redirectUri(),
            ],
            'message' => 'ok',
        ]);
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->oauth->dashboardRedirect('denied');
        }

        $state = $request->query('state');
        $code = $request->query('code');
        if (! is_string($state) || $state === '' || ! is_string($code) || $code === '') {
            return $this->oauth->dashboardRedirect('invalid_state');
        }

        $userId = $this->oauth->pullUserId($state);
        if ($userId === null) {
            return $this->oauth->dashboardRedirect('invalid_state');
        }

        $token = $this->oauth->exchangeCode($code);
        if ($token === null) {
            return $this->oauth->dashboardRedirect('token_exchange');
        }

        $account = $this->sync->connectThreadsToken(
            $token['access_token'],
            $userId,
            $token['expires_at'],
        );
        if ($account === null) {
            return $this->oauth->dashboardRedirect('profile');
        }

        $this->logger->log(User::query()->find($userId), SocialActivityAction::AccountConnected, $account, [
            'platform' => 'threads',
            'via' => 'oauth',
        ]);

        return $this->oauth->dashboardRedirect('connected');
    }
}
