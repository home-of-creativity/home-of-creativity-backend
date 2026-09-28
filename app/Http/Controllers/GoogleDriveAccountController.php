<?php

namespace App\Http\Controllers;

use App\Services\GoogleDriveClient;
use App\Services\GoogleDriveUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Google account whose storage holds report files published to Drive (see GoogleDriveUploader).
 */
class GoogleDriveAccountController extends Controller
{
    public function __construct(
        private GoogleDriveUploader $uploader,
        private GoogleDriveClient $drive,
    ) {}

    public function show(): JsonResponse
    {
        return $this->status('ok');
    }

    /**
     * The OAuth client (Google Cloud › Credentials › OAuth client ID, type Web application).
     */
    public function updateClient(Request $request): JsonResponse
    {
        abort_if($this->uploader->clientFromEnv(), 422, 'The OAuth client is set in the server environment.');

        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:255', 'regex:/\.apps\.googleusercontent\.com$/'],
            'client_secret' => ['required', 'string', 'min:8', 'max:255'],
        ], [
            'client_id.regex' => 'The client ID ends with .apps.googleusercontent.com.',
        ]);

        $this->uploader->saveClient($data['client_id'], $data['client_secret']);

        return $this->status('Saved.');
    }

    public function connect(Request $request): JsonResponse
    {
        abort_unless($this->uploader->oauthConfigured(), 422, 'Add the Google OAuth client first.');

        $state = $this->uploader->rememberState((int) $request->user()->id);

        return response()->json([
            'data' => [
                'authorize_url' => $this->uploader->authorizeUrl($state),
                'redirect_uri' => GoogleDriveUploader::redirectUri(),
            ],
            'message' => 'ok',
        ]);
    }

    public function destroy(): JsonResponse
    {
        $this->uploader->disconnect();

        return $this->status('Disconnected.');
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->backToDashboard('denied');
        }

        $state = $request->query('state');
        $code = $request->query('code');
        if (! is_string($state) || $state === '' || ! is_string($code) || $code === '' || $this->uploader->pullUserId($state) === null) {
            return $this->backToDashboard('invalid_state');
        }

        $tokens = $this->uploader->exchangeCode($code);
        if (is_string($tokens)) {
            return $this->backToDashboard($tokens);
        }

        $about = $this->uploader->about($tokens['access_token']);
        if ($about === null) {
            return $this->backToDashboard('drive_unreachable');
        }

        $this->uploader->connect($tokens, $about['email']);

        // Edit access to HOC Clients reaches every client folder below it.
        $root = trim((string) config('services.google.drive_parent_folder_id'), " \t\n\r\"'");
        if ($root !== '') {
            $this->drive->shareFolderWith($root, $about['email']);
        }

        return $this->backToDashboard(null);
    }

    private function status(string $message): JsonResponse
    {
        return response()->json([
            'data' => $this->uploader->status(),
            'message' => $message,
        ]);
    }

    private function backToDashboard(?string $error): RedirectResponse
    {
        $url = GoogleDriveUploader::dashboardUrl();
        $query = $error === null ? ['drive' => 'connected'] : ['drive_error' => $error];

        return redirect()->away($url.(str_contains($url, '?') ? '&' : '?').http_build_query($query));
    }
}
