<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmployeeStatus;
use App\Enums\StaffAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStaffAccessRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\SocialPageAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StaffAccessController extends Controller
{
    public function __construct(private SocialPageAccess $pages) {}

    public function index(): JsonResponse
    {
        abort_unless(request()->user()?->is_admin, 403);

        $employees = Employee::query()
            ->where('status', EmployeeStatus::Approved)
            ->where('is_active', true)
            ->with(['user.role', 'user.pageGrants'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $employees->map(fn (Employee $employee): array => $this->payload($employee))->all(),
            'pages' => $this->pages->catalog(),
            'abilities' => $this->catalog(),
            'message' => 'ok',
        ]);
    }

    public function update(UpdateStaffAccessRequest $request, Employee $employee): JsonResponse
    {
        abort_unless($employee->isApproved(), 422, 'Only approved employees can receive dashboard access.');

        $roleId = $request->validated('role_id');
        if ($roleId !== null && ! filled($employee->email)) {
            abort(422, 'Add an email on the employee before assigning a role.');
        }

        if ($roleId !== null && $employee->user_id === null) {
            $taken = User::query()->where('email', $employee->email)->exists();
            abort_if($taken, 422, 'This email is already used by another account.');
        }

        $employee = DB::transaction(function () use ($request, $employee, $roleId): Employee {
            $user = $employee->user;

            if ($roleId === null) {
                if ($user) {
                    $user->forceFill(['role_id' => null])->save();
                    $user->pageGrants()->delete();
                }

                return $employee->fresh(['user.role', 'user.pageGrants']);
            }

            if (! $user) {
                $user = User::query()->create([
                    'name' => $employee->name,
                    'email' => $employee->email,
                    'password' => $request->validated('password'),
                    'locale' => 'ar',
                    'is_admin' => false,
                    'role_id' => $roleId,
                ]);
                $employee->forceFill(['user_id' => $user->id])->save();
            } else {
                $updates = [
                    'name' => $employee->name,
                    'role_id' => $roleId,
                ];
                if ($request->filled('password')) {
                    $updates['password'] = $request->validated('password');
                }
                $user->forceFill($updates)->save();
            }

            $keys = array_values(array_unique($request->input('page_keys', [])));
            $user->pageGrants()->delete();
            foreach ($keys as $key) {
                $user->pageGrants()->create(['page_key' => $key]);
            }

            return $employee->fresh(['user.role', 'user.pageGrants']);
        });

        return response()->json([
            'data' => $this->payload($employee),
            'message' => 'Updated.',
        ]);
    }

    /**
     * @return list<array{key: string, group: string}>
     */
    private function catalog(): array
    {
        return array_map(function (string $ability): array {
            $group = match (true) {
                str_starts_with($ability, 'ops.') => 'ops',
                str_starts_with($ability, 'site.') => 'site',
                default => 'social',
            };

            return ['key' => $ability, 'group' => $group];
        }, StaffAbility::values());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Employee $employee): array
    {
        $user = $employee->user;

        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'has_account' => $user !== null,
            'role_id' => $user?->role_id,
            'role_name' => $user?->role?->name,
            'page_keys' => $user?->pageGrants->pluck('page_key')->values()->all() ?? [],
        ];
    }
}
