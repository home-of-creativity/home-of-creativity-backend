<?php

namespace App\Actions;

use App\Models\OpsSetting;
use App\Services\GoogleDriveClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RegisterDriveWatch
{
    public function __construct(private GoogleDriveClient $drive) {}

    public function handle(): bool
    {
        if (! $this->drive->configured()) {
            return false;
        }

        $address = rtrim((string) config('app.url'), '/').'/api/integrations/drive/changed';
        if (! str_starts_with($address, 'https://')) {
            Log::info('Drive watch skipped; APP_URL must be HTTPS.');

            return false;
        }

        $expiresAt = OpsSetting::getValue('drive_watch_expires_at');
        if (filled($expiresAt) && now()->addHours(6)->lt($expiresAt)) {
            return true;
        }

        $previousId = (string) OpsSetting::getValue('drive_watch_channel_id', '');
        $previousResource = (string) OpsSetting::getValue('drive_watch_resource_id', '');
        if ($previousId !== '' && $previousResource !== '') {
            $this->drive->stopChannel($previousId, $previousResource);
        }

        $pageToken = $this->drive->startPageToken();
        if ($pageToken === null) {
            return false;
        }

        $channelToken = OpsSetting::getValue('drive_watch_token')
            ?: (string) config('services.google.drive_watch_token');
        if ($channelToken === '') {
            $channelToken = Str::random(40);
            OpsSetting::setValue('drive_watch_token', $channelToken);
        }

        $channel = $this->drive->watchChanges(
            $pageToken,
            $address,
            (string) Str::uuid(),
            $channelToken,
        );
        if ($channel === null) {
            return false;
        }

        OpsSetting::setValue('drive_watch_channel_id', $channel['id']);
        OpsSetting::setValue('drive_watch_resource_id', $channel['resourceId']);
        OpsSetting::setValue('drive_watch_page_token', $pageToken);
        if (filled($channel['expiration'])) {
            $millis = (int) $channel['expiration'];
            OpsSetting::setValue(
                'drive_watch_expires_at',
                $millis > 0 ? now()->setTimestamp((int) floor($millis / 1000))->toIso8601String() : now()->addHours(20)->toIso8601String(),
            );
        } else {
            OpsSetting::setValue('drive_watch_expires_at', now()->addHours(20)->toIso8601String());
        }

        return true;
    }
}
