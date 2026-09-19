<?php

namespace App\Http\Controllers;

use App\Actions\AdminBotDesk;
use App\Actions\GenerateEmployeeCode;
use App\Actions\PushEmployeeToOdoo;
use App\Actions\ResolveWorkPlan;
use App\Enums\EmployeeProfession;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Services\ClickUpClient;
use App\Support\ResolveServiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminBotController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $telegramId = $this->assertAdmin($request);

        return response()->json([
            'data' => [
                'telegram_user_id' => $telegramId,
                'is_admin' => true,
            ],
            'message' => 'ok',
        ]);
    }

    public function departments(Request $request, ClickUpClient $clickUp): JsonResponse
    {
        $this->assertAdmin($request);

        $labels = [
            'sales' => 'المبيعات',
            'design' => 'التصميم',
            'content' => 'المحتوى',
            'programming' => 'البرمجة',
            'photography' => 'التصوير',
        ];

        $departments = [];
        foreach ($clickUp->departmentLists() as $key => $listId) {
            $departments[] = [
                'id' => $key,
                'name' => $labels[$key] ?? $key,
                'list_id' => $listId,
            ];
        }

        return response()->json(['data' => $departments, 'message' => 'ok']);
    }

    public function members(Request $request, ClickUpClient $clickUp): JsonResponse
    {
        $this->assertAdmin($request);
        $department = (string) $request->query('department', '');
        $listId = $department !== '' ? $clickUp->listIdForDepartment($department) : '';
        $members = $listId !== '' ? $clickUp->membersForList($listId) : $clickUp->members();

        return response()->json(['data' => $members, 'message' => 'ok']);
    }

    public function tasks(Request $request, ClickUpClient $clickUp): JsonResponse
    {
        $this->assertAdmin($request);
        $department = (string) $request->query('department', '');
        abort_unless($department !== '', 422, 'Department is required.');

        $listId = $clickUp->listIdForDepartment($department);
        $tasks = $listId !== '' ? $clickUp->listTasksForList($listId) : [];

        return response()->json(['data' => $tasks, 'message' => 'ok']);
    }

    public function inviteGuest(Request $request, ClickUpClient $clickUp, PushEmployeeToOdoo $pushEmployeeToOdoo, GenerateEmployeeCode $generateEmployeeCode): JsonResponse
    {
        $this->assertAdmin($request);
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'departments' => ['nullable', 'array'],
            'departments.*' => ['string'],
            'profession' => ['nullable', 'string'],
        ]);

        $listIds = [];
        foreach ($validated['departments'] ?? [] as $department) {
            $listId = $clickUp->listIdForDepartment((string) $department);
            if ($listId !== '') {
                $listIds[] = $listId;
            }
        }

        $invite = $clickUp->inviteGuest($validated['email'], array_values(array_unique($listIds)));

        $profession = EmployeeProfession::tryFrom((string) ($validated['profession'] ?? ''))
            ?? EmployeeProfession::Sales;

        $employee = Employee::query()->firstOrCreate(
            ['email' => $validated['email']],
            [
                'code' => $generateEmployeeCode->handle(),
                'name' => $validated['name'],
                'profession' => $profession,
                'status' => EmployeeStatus::Approved,
                'is_active' => true,
            ],
        );
        if (! $employee->wasRecentlyCreated) {
            $employee->forceFill([
                'name' => $validated['name'],
                'profession' => $profession,
                'status' => EmployeeStatus::Approved,
                'is_active' => true,
            ])->save();
        }
        $pushEmployeeToOdoo->handle($employee);

        return response()->json([
            'data' => [
                'invite' => $invite,
                'employee_id' => $employee->id,
            ],
            'message' => $invite['ok'] ? 'Guest invited.' : ($invite['message'] ?? 'Invite failed.'),
        ], $invite['ok'] ? 201 : 422);
    }

    public function assign(Request $request, ClickUpClient $clickUp): JsonResponse
    {
        $this->assertAdmin($request);
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'department' => ['required', 'string'],
            'task_id' => ['required', 'string'],
            'assignee_id' => ['required', 'string'],
        ]);

        $listId = $clickUp->listIdForDepartment($validated['department']);
        $allowed = collect($clickUp->membersForList($listId))->pluck('id')->all();
        if ($allowed !== [] && ! in_array($validated['assignee_id'], $allowed, true)) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Assignee is not a member of that department.',
            ]);
        }

        $clickUp->updateTask($validated['task_id'], null, $validated['assignee_id']);

        return response()->json([
            'data' => ['assigned' => true],
            'message' => 'Assigned.',
        ]);
    }

    public function updateTask(Request $request, ClickUpClient $clickUp): JsonResponse
    {
        $this->assertAdmin($request);
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'department' => ['nullable', 'string'],
            'task_id' => ['required', 'string'],
            'status' => ['nullable', 'string', 'max:80'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:4'],
            'due_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $dueMs = isset($validated['due_hours'])
            ? now()->addHours((int) $validated['due_hours'])->getTimestamp() * 1000
            : null;

        $clickUp->updateTask(
            $validated['task_id'],
            $validated['status'] ?? null,
            null,
            $dueMs,
            isset($validated['priority']) ? (int) $validated['priority'] : null,
        );

        return response()->json([
            'data' => ['updated' => true],
            'message' => 'Task updated.',
        ]);
    }

    public function overview(Request $request, AdminBotDesk $desk): JsonResponse
    {
        $this->assertAdmin($request);

        return response()->json(['data' => $desk->overview(), 'message' => 'ok']);
    }

    public function clients(Request $request, AdminBotDesk $desk): JsonResponse
    {
        $this->assertAdmin($request);

        return response()->json(['data' => $desk->clients(), 'message' => 'ok']);
    }

    public function client(Request $request, int $client, AdminBotDesk $desk): JsonResponse
    {
        $this->assertAdmin($request);

        return response()->json(['data' => $desk->client($client), 'message' => 'ok']);
    }

    public function operations(Request $request, AdminBotDesk $desk): JsonResponse
    {
        $this->assertAdmin($request);

        return response()->json(['data' => $desk->operations(), 'message' => 'ok']);
    }

    public function rebuildPlan(
        Request $request,
        AdminBotDesk $desk,
        ResolveWorkPlan $resolveWorkPlan,
        ResolveServiceRequest $resolveServiceRequest,
    ): JsonResponse {
        $this->assertAdmin($request);
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'request_number' => ['required', 'string'],
        ]);

        $serviceRequest = $resolveServiceRequest->byReference($validated['request_number']);
        $resolveWorkPlan->handle($serviceRequest);

        return response()->json([
            'data' => $desk->operation($serviceRequest->fresh(['client', 'pricingPackage']) ?? $serviceRequest),
            'message' => 'Plan rebuilt.',
        ]);
    }

    public function finance(Request $request, AdminBotDesk $desk): JsonResponse
    {
        $this->assertAdmin($request);

        return response()->json(['data' => $desk->finance(), 'message' => 'ok']);
    }

    public function storeExpense(Request $request, AdminBotDesk $desk): JsonResponse
    {
        $telegramId = $this->assertAdmin($request);
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json([
            'data' => $desk->recordExpense(
                (float) $validated['amount'],
                $validated['category'],
                $validated['note'] ?? null,
                $telegramId,
            ),
            'message' => 'Expense recorded.',
        ], 201);
    }

    private function assertAdmin(Request $request): string
    {
        $telegramId = (string) $request->input('telegram_user_id', $request->query('telegram_user_id', ''));
        abort_unless($telegramId !== '', 422, 'telegram_user_id is required.');

        $allowed = config('services.telegram.admin_telegram_ids', []);
        if (! is_array($allowed) || $allowed === []) {
            abort(403, 'Admin Telegram IDs are not configured.');
        }

        abort_unless(in_array($telegramId, array_map('strval', $allowed), true), 403, 'Not an admin.');

        return $telegramId;
    }
}
