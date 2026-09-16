<?php

namespace App\Http\Controllers;

use App\Actions\GenerateEmployeeCode;
use App\Actions\PushEmployeeToOdoo;
use App\Enums\EmployeeProfession;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Services\ClickUpClient;
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
