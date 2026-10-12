<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ManageCalendarEvents;
use App\Enums\StaffAbility;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Every Google Calendar on the service account's list, inside the photography tab.
 * Who reads and writes each calendar follows ManageCalendarEvents::access(). Staff with
 * the full photography ability add calendars and set which roles reach each one.
 */
class PhotographyCalendarController extends Controller
{
    public function __construct(private ManageCalendarEvents $events) {}

    public function calendars(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $configured = $this->events->configured();

        return response()->json([
            'data' => [
                'configured' => $configured,
                'calendars' => $configured ? $this->events->calendarsFor($user, $request->boolean('fresh')) : [],
                'service_email' => $configured ? $this->events->serviceEmail() : null,
                'can_manage' => $this->canManage($user),
            ],
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $this->manager($request);
        $validated = $request->validate(['calendar_id' => ['required', 'string', 'max:255']]);

        return response()->json([
            'data' => $this->events->subscribe(trim($validated['calendar_id'])),
            'message' => 'أُضيف التقويم.',
        ], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $this->manager($request);
        $validated = $request->validate(['calendar_id' => ['required', 'string', 'max:255']]);
        $this->events->unsubscribe($validated['calendar_id']);

        return response()->json(['message' => 'أُزيل التقويم من القائمة.']);
    }

    public function permissions(Request $request): JsonResponse
    {
        $this->manager($request);
        $validated = $request->validate(['calendar_id' => ['required', 'string', 'max:255']]);

        return response()->json(['data' => $this->events->permissions($validated['calendar_id'])]);
    }

    public function savePermissions(Request $request): JsonResponse
    {
        $this->manager($request);
        $validated = $request->validate([
            'calendar_id' => ['required', 'string', 'max:255'],
            'roles' => ['present', 'array'],
            'roles.*' => ['in:none,view,edit'],
        ]);

        return response()->json([
            'data' => $this->events->setPermissions($validated['calendar_id'], $validated['roles']),
            'message' => 'حُفظت صلاحيات التقويم.',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'calendars' => ['array', 'max:25'],
            'calendars.*' => ['string', 'max:255'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        $from = Carbon::parse($validated['from'], ManageCalendarEvents::ZONE);
        $to = Carbon::parse($validated['to'], ManageCalendarEvents::ZONE);
        abort_if($from->diffInDays($to) > 400, 422, 'المدى أطول من سنة.');
        if (! $this->events->configured()) {
            return response()->json(['data' => [], 'errors' => []]);
        }
        $result = $this->events->events($user, $validated['calendars'] ?? [], $from, $to, $validated['q'] ?? null);

        return response()->json(['data' => $result['events'], 'errors' => $result['errors']]);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'calendar_id' => ['required', 'string', 'max:255'],
            'event_id' => ['required', 'string', 'max:1024'],
        ]);

        return response()->json(['data' => $this->events->find($user, $validated['calendar_id'], $validated['event_id'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'calendar_id' => ['required', 'string', 'max:255'],
            ...$this->eventRules(),
            'start' => ['required', 'string', 'max:40'],
        ]);
        $row = $this->events->create($user, $validated);

        return response()->json([
            'data' => $row,
            'message' => $row['conference_failed'] ? 'حُفظ الحدث، لكن جوجل رفض إنشاء رابط Meet.' : 'حُفظ الحدث.',
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'calendar_id' => ['required', 'string', 'max:255'],
            'event_id' => ['required', 'string', 'max:1024'],
            'scope' => ['nullable', 'in:this,following,all'],
            'target_calendar_id' => ['nullable', 'string', 'max:255'],
            ...$this->eventRules(),
            'start' => ['sometimes', 'required', 'string', 'max:40'],
        ]);
        $input = array_diff_key($validated, array_flip(['calendar_id', 'event_id', 'scope']));
        $row = $this->events->update($user, $validated['calendar_id'], $validated['event_id'], $input, $validated['scope'] ?? 'this');

        return response()->json([
            'data' => $row,
            'message' => $row['conference_failed'] ? 'حُفظ التعديل، لكن جوجل رفض إنشاء رابط Meet.' : 'حُفظ التعديل.',
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'calendar_id' => ['required', 'string', 'max:255'],
            'event_id' => ['required', 'string', 'max:1024'],
            'scope' => ['nullable', 'in:this,following,all'],
            'notify' => ['boolean'],
        ]);
        $this->events->delete($user, $validated['calendar_id'], $validated['event_id'], $validated['scope'] ?? 'this', (bool) ($validated['notify'] ?? false));

        return response()->json(['message' => 'حُذف الحدث.']);
    }

    /** @return array<string, list<string>> */
    private function eventRules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'description' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'all_day' => ['sometimes', 'boolean'],
            'end' => ['sometimes', 'nullable', 'string', 'max:40'],
            'color_id' => ['sometimes', 'nullable', 'in:1,2,3,4,5,6,7,8,9,10,11'],
            'attendees' => ['sometimes', 'array', 'max:50'],
            'attendees.*' => ['email:filter', 'max:255'],
            'reminders' => ['sometimes', 'array'],
            'reminders.use_default' => ['boolean'],
            'reminders.overrides' => ['array', 'max:5'],
            'reminders.overrides.*.method' => ['in:popup,email'],
            'reminders.overrides.*.minutes' => ['integer', 'min:0', 'max:40320'],
            'recurrence' => ['sometimes', 'nullable', 'array', 'max:10'],
            'recurrence.*' => ['string', 'max:500', 'regex:/^(RRULE|EXRULE|RDATE|EXDATE)[:;]/i'],
            'transparency' => ['sometimes', 'in:opaque,transparent'],
            'visibility' => ['sometimes', 'in:default,public,private'],
            'meet' => ['sometimes', 'boolean'],
            'notify' => ['sometimes', 'boolean'],
        ];
    }

    /** Adding calendars and setting which roles reach them is its own operation. */
    private function canManage(User $user): bool
    {
        return $user->canAbility(StaffAbility::OpsPhotographyCalendars);
    }

    private function manager(Request $request): User
    {
        $user = $this->user($request);
        abort_unless($this->canManage($user), 403);

        return $user;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
