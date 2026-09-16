<?php

namespace App\Console\Commands;

use App\Models\ClickUpTask;
use App\Services\ClickUpClient;
use App\Services\GoogleCalendarClient;
use App\Services\TelegramNotifier;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AlertClickUpDueDatesCommand extends Command
{
    protected $signature = 'ops:clickup-due-alerts';

    protected $description = 'Notify admins of ClickUp tasks due within 24 hours.';

    public function handle(
        ClickUpClient $clickUp,
        TelegramNotifier $telegram,
        GoogleCalendarClient $calendar,
    ): int {
        if (! filled(config('services.clickup.token'))) {
            return self::SUCCESS;
        }

        $lists = $clickUp->departmentLists();
        if ($lists === []) {
            return self::SUCCESS;
        }

        $dueTasks = [];
        foreach ($lists as $department => $listId) {
            try {
                $tasks = $clickUp->listTasksForList($listId);
            } catch (Throwable $exception) {
                Log::warning('ClickUp due-alert list failed.', [
                    'department' => $department,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            foreach ($tasks as $task) {
                $taskId = (string) ($task['id'] ?? '');
                if ($taskId === '') {
                    continue;
                }

                $dueAt = $this->parseDueDate($task['due_date'] ?? null);
                if ($dueAt === null || ! $this->isInAlertWindow($dueAt)) {
                    continue;
                }

                $dueTasks[] = [
                    'id' => $taskId,
                    'name' => (string) ($task['name'] ?? $taskId),
                    'url' => (string) ($task['url'] ?? ''),
                    'department' => (string) $department,
                    'due_at' => $dueAt,
                ];
            }
        }

        if ($dueTasks === []) {
            return self::SUCCESS;
        }

        $locals = ClickUpTask::query()
            ->with('request')
            ->whereIn('clickup_task_id', array_column($dueTasks, 'id'))
            ->get()
            ->keyBy('clickup_task_id');

        [$bot, $chatIds] = $this->alertRecipients($telegram);

        foreach ($dueTasks as $task) {
            $local = $locals->get($task['id']);
            $line = "قرب التسليم ({$task['department']}): {$task['name']}";
            if (filled($local?->request?->number)) {
                $line .= ' — '.$local->request->number;
            }

            $dueKey = $task['due_at']->toDateString();
            $telegramKey = "ops:clickup-due:tg:{$task['id']}:{$dueKey}";
            $calendarKey = "ops:clickup-due:cal:{$task['id']}:{$dueKey}";

            if ($bot !== null && $chatIds !== [] && Cache::add($telegramKey, true, now()->addDays(2))) {
                foreach ($chatIds as $chatId) {
                    try {
                        $telegram->send((string) $chatId, $line, $bot);
                    } catch (Throwable $exception) {
                        Cache::forget($telegramKey);
                        Log::warning('Admin due alert failed.', [
                            'task' => $task['id'],
                            'error' => $exception->getMessage(),
                        ]);
                        break;
                    }
                }
            }

            if ($calendar->configured() && Cache::add($calendarKey, true, now()->addDays(2))) {
                try {
                    $eventId = $calendar->createEvent($line, $task['url'], $task['due_at']);
                    if (! filled($eventId)) {
                        Cache::forget($calendarKey);
                    }
                } catch (Throwable $exception) {
                    Cache::forget($calendarKey);
                    Log::warning('ClickUp due calendar event failed.', [
                        'task' => $task['id'],
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: string|null, 1: list<string>}
     */
    private function alertRecipients(TelegramNotifier $telegram): array
    {
        $adminIds = config('services.telegram.admin_telegram_ids', []);
        $adminIds = is_array($adminIds)
            ? array_values(array_filter(array_map(strval(...), $adminIds)))
            : [];

        if ($adminIds !== [] && $telegram->configured('admin')) {
            return ['admin', $adminIds];
        }

        $staffChat = trim((string) config('services.telegram.staff_chat_id', ''));
        if ($staffChat !== '' && $telegram->configured('staff')) {
            return ['staff', [$staffChat]];
        }

        if ($adminIds !== [] && $telegram->configured('staff')) {
            return ['staff', $adminIds];
        }

        return [null, []];
    }

    private function parseDueDate(mixed $due): ?CarbonInterface
    {
        if (! is_numeric($due)) {
            return null;
        }

        $value = (int) $due;
        if ($value <= 0) {
            return null;
        }

        if ($value > 1_000_000_000_000) {
            $value = (int) floor($value / 1000);
        }

        return Carbon::createFromTimestamp($value);
    }

    private function isInAlertWindow(CarbonInterface $dueAt): bool
    {
        return $dueAt->betweenIncluded(now()->subDay(), now()->addDay());
    }
}
