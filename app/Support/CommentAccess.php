<?php

namespace App\Support;

use App\Actions\ManageCalendarEvents;
use App\Enums\SocialAbility;
use App\Enums\StaffAbility;
use App\Models\ClientReport;
use App\Models\ClientReportSlot;
use App\Models\PhotographyBooking;
use App\Models\SocialPost;
use App\Models\User;

/**
 * Who reads and writes comments: whoever can open the thing under them. A report needs
 * the reports view, a post one of the social abilities that show posts, a booking the
 * photography page, and a Google event the photography page plus that calendar.
 */
class CommentAccess
{
    public function __construct(private ManageCalendarEvents $calendars) {}

    public function allows(User $user, string $type, string $id, ?string $calendarId = null): bool
    {
        return match ($type) {
            'report' => $user->canAbility('ops.reports.view')
                && ctype_digit($id) && ClientReport::query()->whereKey((int) $id)->exists(),
            'report_slot' => $user->canAbility('ops.reports.view')
                && ctype_digit($id) && ClientReportSlot::query()->whereKey((int) $id)->exists(),
            'social_post' => $this->seesPosts($user)
                && ctype_digit($id) && SocialPost::query()->whereKey((int) $id)->exists(),
            'photography_booking' => $user->canAbility(StaffAbility::OpsPhotography)
                && ctype_digit($id) && PhotographyBooking::query()->whereKey((int) $id)->exists(),
            'calendar_event' => $user->canAbility(StaffAbility::OpsPhotography)
                && filled($calendarId) && $id !== ''
                && $this->calendars->access($user, (string) $calendarId)['view'],
            default => false,
        };
    }

    /** Anyone may change their own comment; deleting another's needs full control. */
    public function canDelete(User $user, int $authorId): bool
    {
        return $user->id === $authorId || ($user->role_id === null && $user->is_admin);
    }

    private function seesPosts(User $user): bool
    {
        return $user->canSocial(SocialAbility::Create)
            || $user->canSocial(SocialAbility::Approve)
            || $user->canSocial(SocialAbility::Engage)
            || $user->canSocial(SocialAbility::Accounts);
    }
}
