<?php

namespace App\Services;

use App\Enums\IntegrationEventStatus;
use App\Models\IntegrationEvent;
use App\Models\OpsSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DevDigest
{
    public function __construct(private DevBeat $beats) {}

    public function text(): string
    {
        $scheduler = $this->beats->ageSeconds('scheduler');
        $deployed = $this->beats->read('deploy');
        $expires = OpsSetting::getValue('drive_watch_expires_at');
        $secret = (string) config('services.sentry.webhook_secret');

        $lines = [
            'ملخص المطورين',
            'السيرفر: '.((bool) Cache::get('dev.health.down') ? 'متوقف' : 'يعمل'),
            'المجدول: '.($scheduler === null ? 'لا نبض' : 'منذ '.$scheduler.' ث'),
            'البوتات: '.$this->botLine(),
            'الطابور الفاشل: '.$this->failedJobs(),
            'تكامل عالق: '.$this->stuckOutbox(),
            'مراقبة Drive: '.$this->driveLine($expires),
            'Sentry: '.($secret !== '' ? 'POST /api/integrations/sentry' : 'SENTRY_WEBHOOK_SECRET غير مضبوط'),
            'آخر نشر: '.($deployed ?? 'غير مسجل'),
        ];

        return implode("\n", $lines);
    }

    public function botsText(): string
    {
        return $this->botLine();
    }

    public function queueText(): string
    {
        return 'الطابور الفاشل: '.$this->failedJobs();
    }

    private function botLine(): string
    {
        $parts = [];
        foreach ($this->labels() as $name => $label) {
            $age = $this->beats->ageSeconds($name);
            $parts[] = $label.' '.($age !== null && $age <= 600 ? 'يعمل' : 'متوقف');
        }

        return implode('، ', $parts);
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return [
            'client' => 'العميل',
            'staff' => 'الموظفون',
            'admin' => 'الإدارة',
            'dev' => 'المطورون',
        ];
    }

    private function failedJobs(): int
    {
        return (int) DB::table('failed_jobs')->count();
    }

    private function stuckOutbox(): int
    {
        return IntegrationEvent::query()
            ->where('status', IntegrationEventStatus::Failed)
            ->where('attempts', '>=', 5)
            ->count();
    }

    private function driveLine(?string $expires): string
    {
        if ($expires === null || $expires === '') {
            return 'غير مسجلة';
        }

        return now()->gt($expires) ? 'منتهية '.$expires : 'تنتهي '.$expires;
    }
}
