<?php

namespace App\Support;

use App\Enums\StaffAbility;
use App\Models\ReportCalendar;
use App\Models\ReportDeadline;
use App\Models\Role;
use App\Models\User;

/**
 * Who sees which reports calendar. The main calendar (no calendar id) follows the
 * reports view; every other calendar is its own role ability. An admin without a
 * role sees them all.
 */
class ReportCalendarAccess
{
    public function canManage(?User $user): bool
    {
        return (bool) $user?->canAbility(StaffAbility::OpsReportCalendars);
    }

    public function canView(?User $user, ?int $calendarId): bool
    {
        if ($calendarId === null) {
            return true;
        }

        return (bool) $user?->canAbility(ReportCalendar::keyFor($calendarId));
    }

    public function canSeeDeadline(?User $user, ReportDeadline $deadline): bool
    {
        return $this->canView($user, $deadline->report_calendar_id === null ? null : (int) $deadline->report_calendar_id);
    }

    /**
     * Calendars this staff member sees, oldest first.
     *
     * @return list<ReportCalendar>
     */
    public function visible(?User $user): array
    {
        return ReportCalendar::query()->orderBy('id')->get()
            ->filter(fn (ReportCalendar $calendar): bool => $this->canView($user, $calendar->id))
            ->values()
            ->all();
    }

    /**
     * The calendar as the dashboard shows it, with the roles that see it.
     *
     * @return array<string, mixed>
     */
    public function payload(ReportCalendar $calendar): array
    {
        $roleIds = $this->roleIds($calendar);

        return [
            'id' => $calendar->id,
            'name' => $calendar->name,
            'color' => $calendar->color,
            'ability' => $calendar->abilityKey(),
            'role_ids' => $roleIds,
            'roles' => Role::query()->whereKey($roleIds)->orderBy('name')->pluck('name')->all(),
            'deadlines_count' => $calendar->deadlines()->count(),
        ];
    }

    /**
     * Role ids that hold this calendar's view ability.
     *
     * @return list<int>
     */
    public function roleIds(ReportCalendar $calendar): array
    {
        $key = $calendar->abilityKey();

        return Role::query()->orderBy('name')->get()
            ->filter(fn (Role $role): bool => in_array($key, $role->abilities ?? [], true))
            ->map(fn (Role $role): int => $role->id)
            ->values()
            ->all();
    }

    /**
     * Gives the calendar to exactly these roles and takes it from the rest.
     *
     * @param  list<int>  $roleIds
     */
    public function syncRoles(ReportCalendar $calendar, array $roleIds): void
    {
        $key = $calendar->abilityKey();
        foreach (Role::query()->get() as $role) {
            $abilities = array_values(array_filter($role->abilities ?? [], fn ($ability): bool => $ability !== $key));
            if (in_array($role->id, $roleIds, true)) {
                $abilities[] = $key;
            }
            if ($abilities !== array_values($role->abilities ?? [])) {
                $role->forceFill(['abilities' => $abilities])->save();
            }
        }
    }
}
