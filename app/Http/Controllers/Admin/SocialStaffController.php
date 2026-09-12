<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SocialAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSocialStaffPermissionsRequest;
use App\Http\Resources\UserResource;
use App\Models\User;

class SocialStaffController extends Controller
{
    public function index()
    {
        abort_unless(request()->user()?->canSocial(SocialAbility::Accounts), 403);

        return UserResource::collection(
            User::query()
                ->where('is_admin', true)
                ->orderBy('name')
                ->get()
        )->additional(['message' => 'ok']);
    }

    public function update(UpdateSocialStaffPermissionsRequest $request, User $user): UserResource
    {
        abort_unless($user->is_admin, 422, 'Only dashboard staff can receive social permissions.');

        $user->forceFill([
            'social_permissions' => $request->input('social_permissions'),
        ])->save();

        return UserResource::make($user->fresh())->additional(['message' => 'Updated.']);
    }
}
