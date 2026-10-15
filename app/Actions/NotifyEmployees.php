<?php

namespace App\Actions;

use App\Contracts\WhatsAppMessenger;
use App\Enums\EmployeeProfession;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Services\TelegramNotifier;
use App\Support\ClientPhone;
use Illuminate\Support\Facades\Log;

class NotifyEmployees
{
    /** Staff replies on the company number reach the client bot, so they are told not to answer. */
    private const AUTOMATED = "\n\nرسالة آلية من النظام، لا داعي للرد.";

    public function __construct(
        private TelegramNotifier $telegram,
        private WhatsAppMessenger $whatsApp,
    ) {}

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
     */
    public function toEmployee(Employee $employee, string $text, ?array $inlineButtons = null): bool
    {
        if (! filled($employee->telegram_user_id) || ! $employee->isApproved()) {
            return false;
        }

        return $this->deliver((string) $employee->telegram_user_id, $text, 'employee:'.$employee->id, $inlineButtons);
    }

    /**
     * The employee's own WhatsApp from the company number, or Telegram when they have no
     * readable phone or WhatsApp is off.
     */
    public function toEmployeeWhatsApp(Employee $employee, string $text): bool
    {
        if (! $employee->isApproved()) {
            return false;
        }
        $digits = ClientPhone::whatsappDigits($employee->phone);
        if ($digits !== '' && $this->whatsApp->configured()) {
            try {
                $this->whatsApp->sendText($digits, $text.self::AUTOMATED);

                return true;
            } catch (\Throwable $exception) {
                Log::warning('Employee WhatsApp notify failed.', [
                    'employee_id' => $employee->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $this->toEmployee($employee, $text);
    }

    /**
     * Every approved employee of a profession on WhatsApp, Telegram for those without it.
     */
    public function professionWhatsApp(EmployeeProfession $profession, string $text): int
    {
        $sent = 0;
        Employee::query()->approved()->where('profession', $profession)->get()
            ->each(function (Employee $employee) use ($text, &$sent): void {
                if ($this->toEmployeeWhatsApp($employee, $text)) {
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * The management WhatsApp numbers, or each Telegram admin when none are set.
     */
    public function management(string $text): int
    {
        return $this->toNumbers((array) config('services.whatsapp.management_numbers', []), $text);
    }

    /**
     * The support WhatsApp numbers, then the management ones, then the Telegram admins.
     */
    public function support(string $text): int
    {
        $numbers = (array) config('services.whatsapp.support_numbers', []);

        return $this->toNumbers($numbers !== [] ? $numbers : (array) config('services.whatsapp.management_numbers', []), $text);
    }

    /**
     * @param  array<int, mixed>  $configured
     */
    private function toNumbers(array $configured, string $text): int
    {
        $numbers = array_values(array_filter(array_map(
            fn ($number): string => ClientPhone::whatsappDigits((string) $number),
            $configured,
        )));
        if ($numbers === [] || ! $this->whatsApp->configured()) {
            return $this->handleAdmins($text);
        }
        $sent = 0;
        foreach ($numbers as $number) {
            try {
                $this->whatsApp->sendText($number, $text.self::AUTOMATED);
                $sent++;
            } catch (\Throwable $exception) {
                Log::warning('Staff WhatsApp notify failed.', ['error' => $exception->getMessage()]);
            }
        }

        return $sent > 0 ? $sent : $this->handleAdmins($text);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>|null  $inlineButtons
     */
    public function handleAdmins(string $text, ?array $inlineButtons = null): int
    {
        $ids = array_values(array_filter(array_map(
            strval(...),
            (array) config('services.telegram.admin_telegram_ids', []),
        )));
        if ($ids === [] || ! $this->telegram->configured('admin')) {
            return 0;
        }

        $sent = 0;
        foreach ($ids as $id) {
            try {
                if ($inlineButtons !== null && $inlineButtons !== []) {
                    $this->telegram->sendInlineActions($id, $text, $inlineButtons, 'admin');
                } else {
                    $this->telegram->send($id, $text, 'admin');
                }
                $sent++;
            } catch (\Throwable $exception) {
                Log::warning('Admin Telegram notify failed.', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
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
