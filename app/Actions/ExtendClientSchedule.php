<?php

namespace App\Actions;

use App\Models\ServiceRequest;
use App\Services\ClickUpClient;
use App\Support\ResolveServiceRequest;
use App\Support\WorkCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Extends a request for a reason on our side: the delivery date and every open ClickUp
 * task's due date move by the same working time, and the client is told the new date.
 */
class ExtendClientSchedule
{
    private const FINISHED = ['complete', 'completed', 'closed', 'done'];

    public function __construct(
        private WorkCalendar $calendar,
        private NotifyClientChannels $notifyClientChannels,
        private ClickUpClient $clickUp,
    ) {}

    /** Working days, each the team's hours per day. */
    public function byDays(ServiceRequest $request, int $days, string $reason): ServiceRequest
    {
        if ($days < 1) {
            throw ValidationException::withMessages(['days' => 'عدد أيام التمديد مطلوب.']);
        }

        return $this->extend($request, $days * $this->calendar->hoursPerDay(), $reason, $days);
    }

    public function handle(ServiceRequest $request, int $hours, string $reason): ServiceRequest
    {
        return $this->extend($request, $hours, $reason, null);
    }

    private function extend(ServiceRequest $request, int $hours, string $reason, ?int $days): ServiceRequest
    {
        $reason = trim($reason);
        if ($reason === '' || $hours < 1) {
            throw ValidationException::withMessages([
                'reason' => 'سبب زيادة المدة مطلوب.',
            ]);
        }

        $base = $request->client_due_at ?? $request->subscription_ends_at ?? now();
        $due = $this->calendar->addWorkHours($base, $hours);
        $request->forceFill([
            'client_due_at' => $due,
            'schedule_extension_reason' => $reason,
            'work_plan' => $this->shiftPlan($request, $hours),
        ])->save();
        $this->shiftClickUp($request->fresh(['clickupTasks']) ?? $request, $hours);

        $number = ResolveServiceRequest::displayNumber($request);
        $length = $days !== null ? self::daysText($days) : "{$hours} ساعة عمل";
        $this->notifyClientChannels->send(
            $request,
            "تمديد مدة الطلب #{$number} {$length} لظرف من الشركة.\n"
            .'موعد التسليم الجديد: '.self::dateText($due)."\n{$reason}",
        );

        return $request->fresh() ?? $request;
    }

    /**
     * The plan's due dates move with the request.
     *
     * @return array<string, mixed>|null
     */
    private function shiftPlan(ServiceRequest $request, int $hours): ?array
    {
        $plan = $request->work_plan;
        if (! is_array($plan) || ! is_array($plan['operations'] ?? null)) {
            return $plan;
        }
        foreach ($plan['operations'] as $index => $operation) {
            if (is_array($operation) && filled($operation['due_at'] ?? null)) {
                $plan['operations'][$index]['due_at'] = $this->calendar
                    ->addWorkHours(Carbon::parse((string) $operation['due_at']), $hours)
                    ->toIso8601String();
            }
        }
        if (filled($plan['client_due_at'] ?? null)) {
            $plan['client_due_at'] = $this->calendar->addWorkHours(Carbon::parse((string) $plan['client_due_at']), $hours)->toIso8601String();
        }

        return $plan;
    }

    /** Every open task's due date moves by the same working time. */
    private function shiftClickUp(ServiceRequest $request, int $hours): void
    {
        if (! $this->clickUp->configured()) {
            return;
        }
        $operations = collect(data_get($request->work_plan, 'operations', []))->filter(fn (mixed $row): bool => is_array($row));
        foreach ($request->clickupTasks as $task) {
            if (! filled($task->clickup_task_id) || in_array(strtolower((string) $task->status), self::FINISHED, true)) {
                continue;
            }
            // The plan already holds the moved date for its department; others move from the request date.
            $planned = $operations->first(fn (array $row): bool => (string) ($row['department'] ?? '') === $task->task_type?->value);
            $due = filled($planned['due_at'] ?? null)
                ? Carbon::parse((string) $planned['due_at'])
                : $request->client_due_at;
            if ($due === null) {
                continue;
            }
            try {
                $this->clickUp->updateTask((string) $task->clickup_task_id, null, null, $due->getTimestampMs());
                $this->clickUp->addTaskComment((string) $task->clickup_task_id, 'مُدّد موعد التسليم إلى '.self::dateText($due).'.');
            } catch (Throwable $exception) {
                Log::warning('ClickUp due date was not extended.', ['task' => $task->clickup_task_id, 'error' => $exception->getMessage()]);
            }
        }
    }

    private static function daysText(int $days): string
    {
        return match (true) {
            $days === 1 => 'يوم عمل واحد',
            $days === 2 => 'يومي عمل',
            $days <= 10 => "{$days} أيام عمل",
            default => "{$days} يوم عمل",
        };
    }

    private static function dateText(CarbonInterface $date): string
    {
        return $date->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i');
    }
}
