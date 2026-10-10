<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Services\GeminiService;
use App\Support\WorkCalendar;
use App\Support\WorkLines;
use Illuminate\Support\Facades\Cache;

class ResolveWorkPlan
{
    public const PACKAGE_TTL_SECONDS = 60 * 60 * 24 * 30;

    public const MANUAL_TTL_SECONDS = 60 * 60 * 24 * 7;

    public function __construct(
        private GeminiService $gemini,
        private WorkCalendar $calendar,
    ) {}

    /**
     * @return array{source: string, cache_key: string, operations: list<array<string, mixed>>}
     */
    public function handle(ServiceRequest $request, bool $rebuild = false): array
    {
        $request->loadMissing(['pricingPackage.subcategory.category', 'client', 'clickupTasks']);

        if (! $rebuild && $request->clickupTasks->isNotEmpty() && is_array($request->work_plan) && ($request->work_plan['operations'] ?? []) !== []) {
            return $request->work_plan;
        }

        $key = $this->cacheKey($request);
        $ttl = $request->pricing_package_id ? self::PACKAGE_TTL_SECONDS : self::MANUAL_TTL_SECONDS;
        $fromCache = Cache::has($key);

        $template = Cache::remember($key, $ttl, fn (): array => $this->planTemplate($request));

        $templateList = is_array($template) ? $template : [];
        $photographyHours = 0;
        $executable = [];
        foreach ($templateList as $operation) {
            if (($operation['department'] ?? '') === 'photography') {
                $photographyHours += (int) ($operation['hours'] ?? 0);

                continue;
            }
            $executable[] = $operation;
        }
        $operations = $this->assignEmployees($executable);
        $parallel = $this->calendar->parallelHours($operations);
        $plan = [
            'source' => WorkLines::fromRequest($request) !== [] ? 'lines' : ($fromCache ? 'cache' : 'ai'),
            'cache_key' => $key,
            'period_key' => (string) data_get($request->work_plan, 'period_key', 'initial'),
            'parallel_hours' => $parallel,
            'photography_hours' => $photographyHours,
            'period_end' => $request->subscription_ends_at?->toIso8601String(),
            'client_due_at' => $this->calendar->addWorkHours(now(), $parallel)->toIso8601String(),
            'operations' => $operations,
        ];

        $request->forceFill(['work_plan' => $plan])->save();

        return $plan;
    }

    public function cacheKey(ServiceRequest $request): string
    {
        $lines = WorkLines::fromRequest($request);
        if ($lines !== []) {
            return 'hoc:work-plan:v2:lines:'.sha1((string) json_encode($lines));
        }

        if ($request->pricing_package_id) {
            return 'hoc:work-plan:v1:pkg:'.$request->pricing_package_id;
        }

        $normalized = mb_strtolower(trim($request->title.'|'.$request->description));

        return 'hoc:work-plan:v1:manual:'.sha1($normalized);
    }

    /**
     * @return list<array{department: string, brief: string, hours: int, priority: int}>
     */
    private function planTemplate(ServiceRequest $request): array
    {
        $package = $request->pricingPackage;
        $context = null;
        if ($package) {
            $features = is_array($package->features) ? implode("\n", array_map(
                fn ($feature) => is_array($feature) ? (string) ($feature['ar'] ?? $feature['en'] ?? json_encode($feature)) : (string) $feature,
                $package->features,
            )) : '';
            $context = trim(
                ($package->name_ar ?: $package->name_en ?: '')."\n"
                .($package->subcategory?->category?->name_ar ?: '')."\n"
                .$features
            );
        }

        $lines = WorkLines::fromRequest($request);
        if ($lines !== []) {
            return $lines;
        }

        return $this->gemini->planWork(
            (string) $request->title,
            (string) $request->description,
            $context,
        );
    }

    /**
     * @param  list<array{department: string, brief: string, hours: int, priority: int}>  $template
     * @return list<array<string, mixed>>
     */
    private function assignEmployees(array $template): array
    {
        $assigned = [];

        foreach ($template as $operation) {
            $profession = $this->professionFor((string) ($operation['department'] ?? ''));
            $employee = $profession ? $this->pickEmployee($profession) : null;
            $hours = (int) ($operation['hours'] ?? 24);
            $due = $this->calendar->addWorkHours(now(), $hours);

            $assigned[] = [
                'department' => $operation['department'],
                'brief' => $operation['brief'],
                'hours' => $hours,
                'priority' => (int) ($operation['priority'] ?? 3),
                'priority_label' => $this->priorityLabel((int) ($operation['priority'] ?? 3)),
                'due_at' => $due->toIso8601String(),
                'employee_id' => $employee?->id,
                'employee_name' => $employee?->name,
                'clickup_user_id' => $employee?->clickup_user_id,
            ];
        }

        return $assigned;
    }

    private function pickEmployee(EmployeeProfession $profession): ?Employee
    {
        $finished = ['complete', 'closed', 'done', 'completed'];

        return Employee::query()
            ->approved()
            ->where('profession', $profession)
            ->withSum(['clickupTasks as remaining_hours' => function ($query) use ($finished): void {
                $query->where(function ($inner) use ($finished): void {
                    $inner->whereNull('status')->orWhereNotIn('status', $finished);
                });
            }], 'planned_hours')
            ->orderByRaw('clickup_user_id is null')
            ->orderByRaw('coalesce(remaining_hours, 0)')
            ->orderBy('id')
            ->first();
    }

    private function professionFor(string $department): ?EmployeeProfession
    {
        return match ($department) {
            'design' => EmployeeProfession::Design,
            'content' => EmployeeProfession::Content,
            'programming' => EmployeeProfession::Web,
            'photography' => EmployeeProfession::Media,
            default => null,
        };
    }

    private function priorityLabel(int $priority): string
    {
        return match ($priority) {
            1 => 'عاجلة',
            2 => 'عالية',
            3 => 'عادية',
            4 => 'منخفضة',
            default => 'عادية',
        };
    }
}
