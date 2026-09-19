<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\Log;

class NotifyEmployees
{
    public function __construct(private TelegramNotifier $telegram) {}

    /**
     * @param  list<array{text: string, callback_data: string}>|null  $inlineButtons
     * @param  array{path: string, mime?: string|null, name?: string|null}|null  $attachment
     */
    public function handle(
        ServiceRequest $request,
        EmployeeProfession $profession,
        string $text,
        ?array $inlineButtons = null,
        ?array $attachment = null,
    ): int {
        return $this->notifyProfession($profession, $text, $request->number, $inlineButtons, $attachment);
    }

    public function handlePlain(EmployeeProfession $profession, string $text): int
    {
        return $this->notifyProfession($profession, $text, 'staff-join');
    }

    /**
     * @param  list<array{text: string, callback_data: string}>|null  $inlineButtons
     * @param  array{path: string, mime?: string|null, name?: string|null}|null  $attachment
     */
    private function notifyProfession(
        EmployeeProfession $profession,
        string $text,
        string $context,
        ?array $inlineButtons = null,
        ?array $attachment = null,
    ): int {
        $sent = 0;
        $employees = Employee::query()
            ->approved()
            ->where('profession', $profession)
            ->whereNotNull('telegram_user_id')
            ->get();

        foreach ($employees as $employee) {
            if ($this->deliver((string) $employee->telegram_user_id, $text, $context, $inlineButtons, $attachment)) {
                $sent++;
            }
        }

        if ($profession === EmployeeProfession::Sales && $sent === 0) {
            $fallback = (string) config('services.telegram.staff_chat_id');
            if ($fallback !== '' && $this->deliver($fallback, $text, $context, $inlineButtons, $attachment)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param  list<array{text: string, callback_data: string}>|null  $inlineButtons
     * @param  array{path: string, mime?: string|null, name?: string|null}|null  $attachment
     */
    private function deliver(
        string $chatId,
        string $text,
        string $context,
        ?array $inlineButtons = null,
        ?array $attachment = null,
    ): bool {
        $bot = $this->telegram->configured('staff') ? 'staff' : 'client';
        if (! $this->telegram->configured($bot)) {
            Log::warning('Employee Telegram notify skipped; bot is not configured.', [
                'context' => $context,
            ]);

            return false;
        }

        try {
            if ($attachment !== null && is_file($attachment['path'])) {
                $markup = $inlineButtons !== null && $inlineButtons !== []
                    ? ['inline_keyboard' => [$inlineButtons]]
                    : null;
                $this->telegram->sendFile(
                    $chatId,
                    $attachment['path'],
                    (string) ($attachment['mime'] ?? 'application/octet-stream'),
                    $text,
                    $bot,
                    $markup,
                );

                return true;
            }

            if ($inlineButtons !== null && $inlineButtons !== []) {
                $this->telegram->sendInlineActions($chatId, $text, $inlineButtons, $bot);
            } else {
                $this->telegram->send($chatId, $text, $bot);
            }

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Employee Telegram notify failed.', [
                'context' => $context,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
