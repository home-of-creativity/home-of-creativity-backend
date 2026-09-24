<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmployeeStatus;
use App\Enums\StaffAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStaffAccessRequest;
use App\Models\Employee;
use App\Models\Role;
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

            $user->pageGrants()->delete();
            foreach ($this->grantsFor($request, (int) $roleId) as $grant) {
                $user->pageGrants()->create($grant);
            }

            return $employee->fresh(['user.role', 'user.pageGrants']);
        });

        return response()->json([
            'data' => $this->payload($employee),
            'message' => 'Updated.',
        ]);
    }

    /**
     * @return list<array{ability: string, page_key: string}>
     */
    private function grantsFor(UpdateStaffAccessRequest $request, int $roleId): array
    {
        $incoming = $request->input('page_grants');
        if (is_array($incoming)) {
            $seen = [];
            $grants = [];
            foreach ($incoming as $grant) {
                $ability = (string) ($grant['ability'] ?? '');
                $pageKey = (string) ($grant['page_key'] ?? '');
                $token = $ability.'|'.$pageKey;
                if ($ability === '' || $pageKey === '' || isset($seen[$token])) {
                    continue;
                }
                $seen[$token] = true;
                $grants[] = ['ability' => $ability, 'page_key' => $pageKey];
            }

            return $grants;
        }

        $role = Role::query()->find($roleId);
        $abilities = array_values(array_intersect($role?->abilities ?? [], StaffAbility::pageScoped()));
        $grants = [];
        foreach ($abilities as $ability) {
            foreach (array_values(array_unique($request->input('page_keys', []))) as $pageKey) {
                $grants[] = ['ability' => $ability, 'page_key' => $pageKey];
            }
        }

        return $grants;
    }

    /**
     * @return list<array{key: string, group: string, actions: list<string>}>
     */
    private function catalog(): array
    {
        return array_map(function (StaffAbility $ability): array {
            $group = match (true) {
                str_starts_with($ability->value, 'ops.') => 'ops',
                str_starts_with($ability->value, 'site.') => 'site',
                default => 'social',
            };

            return [
                'key' => $ability->value,
                'group' => $group,
                'actions' => in_array($ability->value, StaffAbility::crudResources(), true)
                    ? StaffAbility::crudActions()
                    : [],
            ];
        }, StaffAbility::cases());
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
            'page_keys' => $user?->pageGrants->pluck('page_key')->unique()->values()->all() ?? [],
            'page_grants' => $user?->pageGrants->map(fn ($grant): array => [
                'ability' => $grant->ability,
                'page_key' => $grant->page_key,
            ])->values()->all() ?? [],
        ];
    }
}
