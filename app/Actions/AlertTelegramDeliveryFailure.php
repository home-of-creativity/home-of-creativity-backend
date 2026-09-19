<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Models\OpsFollowUp;
use App\Models\ServiceRequest;
use App\Services\TelegramNotifier;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Log;

class AlertTelegramDeliveryFailure
{
    public function __construct(
        private NotifyEmployees $notifyEmployees,
        private TelegramNotifier $telegram,
    ) {}

    public function handle(ServiceRequest $request, string $artifact, string $error, ?string $detail = null): void
    {
        $request->loadMissing('client');
        $dedupe = $artifact.':'.$request->id.($detail !== null && $detail !== '' ? ':'.$detail : '');
        if (! OpsFollowUp::claim(OpsFollowUp::KIND_TELEGRAM_FAIL, $dedupe, $request, [
            'artifact' => $artifact,
            'error' => mb_substr($error, 0, 240),
        ])) {
            return;
        }

        $label = match ($artifact) {
            'quotation' => 'عرض السعر',
            'invoice' => 'الفاتورة',
            'drive' => 'ملف Drive',
            default => $artifact,
        };
        $ref = ResolveServiceRequest::displayNumber($request);
        $who = trim(($request->client?->name ?? '').($request->client?->company_name ? ' — '.$request->client->company_name : ''));
        $lines = [
            "فشل إيصال تلغرام ({$label})",
            "#{$ref} — {$request->title}",
        ];
        if ($who !== '') {
            $lines[] = $who;
        }
        if (filled($detail) && $artifact === 'drive') {
            $lines[] = $detail;
        }
        $lines[] = $error;
        $contact = $request->client?->telegramContactLine();
        if (filled($contact)) {
            $lines[] = $contact;
        }

        $text = implode("\n", $lines);
        $this->notifyEmployees->handle($request, EmployeeProfession::Sales, $text);
        $this->notifyAdmins($text);
    }

    private function notifyAdmins(string $text): void
    {
        $adminIds = config('services.telegram.admin_telegram_ids', []);
        $adminIds = is_array($adminIds)
            ? array_values(array_filter(array_map(strval(...), $adminIds)))
            : [];
        if ($adminIds === [] || ! $this->telegram->configured('admin')) {
            return;
        }

        foreach ($adminIds as $chatId) {
            try {
                $this->telegram->send($chatId, $text, 'admin');
            } catch (\Throwable $exception) {
                Log::warning('Telegram delivery-failure admin alert failed.', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
