<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        abort_unless(request()->user()?->is_admin, 403);

        return RoleResource::collection(
            Role::query()->withCount('users')->orderBy('name')->get()
        )->additional(['message' => 'ok']);
    }

    public function store(StoreRoleRequest $request): RoleResource
    {
        $role = Role::query()->create([
            'name' => $request->validated('name'),
            'abilities' => array_values(array_unique($request->validated('abilities'))),
        ]);

        return RoleResource::make($role->loadCount('users'))
            ->additional(['message' => 'Created.']);
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $role->update([
            'name' => $request->validated('name'),
            'abilities' => array_values(array_unique($request->validated('abilities'))),
        ]);

        return RoleResource::make($role->fresh()->loadCount('users'))
            ->additional(['message' => 'Updated.']);
    }

    public function destroy(Role $role)
    {
        abort_unless(request()->user()?->is_admin, 403);
        abort_if($role->users()->exists(), 422, 'Unassign this role before deleting it.');

        $role->delete();

        return response()->json([
            'data' => null,
            'message' => 'Deleted.',
        ]);
    }
}
