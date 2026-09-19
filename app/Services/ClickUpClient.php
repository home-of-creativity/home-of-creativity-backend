<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClickUpClient
{
    public function configured(): bool
    {
        return filled(config('services.clickup.token'))
            && filled($this->defaultListId());
    }

    /**
     * @param  array<int, array{department: string, brief: string, clickup_user_id?: string|null, due_at?: string|null, priority?: int|null}>  $briefs
     * @return array<int, array{department: string, brief: string, clickup_task_id: string}>
     */
    public function createTasks(string $requestNumber, array $briefs): array
    {
        $created = [];

        foreach ($briefs as $brief) {
            $listId = $this->listIdForDepartment((string) $brief['department']);
            $payload = [
                'name' => $requestNumber.' · '.$brief['department'],
                'description' => $brief['brief'],
                'tags' => ['hoc', $brief['department']],
            ];
            if (filled($brief['clickup_user_id'] ?? null)) {
                $assignee = (string) $brief['clickup_user_id'];
                $payload['assignees'] = [ctype_digit($assignee) ? (int) $assignee : $assignee];
            }
            if (isset($brief['priority']) && is_numeric($brief['priority'])) {
                $payload['priority'] = max(1, min(4, (int) $brief['priority']));
            }
            if (filled($brief['due_at'] ?? null)) {
                $due = strtotime((string) $brief['due_at']);
                if ($due) {
                    $payload['due_date'] = $due * 1000;
                }
            }
            $response = Http::timeout((int) config('services.clickup.timeout', 12))
                ->connectTimeout(3)
                ->retry(2, 200)
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => (string) config('services.clickup.token'),
                ])
                ->post('https://api.clickup.com/api/v2/list/'.$listId.'/task', $payload)
                ->throw();

            $taskId = $response->json('id');
            if (! is_string($taskId) || $taskId === '') {
                throw new RuntimeException('ClickUp did not return a task id.');
            }

            $created[] = [
                'department' => $brief['department'],
                'brief' => $brief['brief'],
                'clickup_task_id' => $taskId,
            ];
        }

        return $created;
    }

    public function createSalesIntakeTask(string $requestNumber, string $title, string $description): string
    {
        $listId = $this->listIdForDepartment('sales');
        $response = Http::timeout((int) config('services.clickup.timeout', 12))
            ->connectTimeout(3)
            ->retry(2, 200)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => (string) config('services.clickup.token'),
            ])
            ->post('https://api.clickup.com/api/v2/list/'.$listId.'/task', [
                'name' => $requestNumber.' · '.$title,
                'description' => $description,
                'tags' => ['hoc', 'sales', 'intake'],
            ])
            ->throw();

        $taskId = $response->json('id');
        if (! is_string($taskId) || $taskId === '') {
            throw new RuntimeException('ClickUp did not return a task id.');
        }

        return $taskId;
    }

    public function updateTask(string $taskId, ?string $status = null, ?string $assigneeId = null, ?int $dueDateMs = null, ?int $priority = null): void
    {
        $payload = [];

        if (filled($status)) {
            $payload['status'] = $status;
        }

        if (filled($assigneeId)) {
            $payload['assignees'] = [
                'add' => [ctype_digit($assigneeId) ? (int) $assigneeId : $assigneeId],
            ];
        }

        if ($dueDateMs !== null && $dueDateMs > 0) {
            $payload['due_date'] = $dueDateMs;
        }

        if ($priority !== null) {
            $payload['priority'] = max(1, min(4, $priority));
        }

        if ($payload === []) {
            return;
        }

        Http::timeout((int) config('services.clickup.timeout', 12))
            ->connectTimeout(3)
            ->retry(2, 200)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => (string) config('services.clickup.token'),
            ])
            ->put('https://api.clickup.com/api/v2/task/'.$taskId, $payload)
            ->throw();
    }

    public function addTaskComment(string $taskId, string $comment): void
    {
        Http::timeout((int) config('services.clickup.timeout', 12))
            ->connectTimeout(3)
            ->retry(2, 200)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => (string) config('services.clickup.token'),
            ])
            ->post("https://api.clickup.com/api/v2/task/{$taskId}/comment", [
                'comment_text' => $comment,
            ])
            ->throw();
    }

    public function addTaskAttachment(string $taskId, string $absolutePath, string $filename): void
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('Attachment file not found.');
        }

        Http::timeout(60)
            ->connectTimeout(5)
            ->retry(2, 500)
            ->attach('attachment', fopen($absolutePath, 'r'), $filename)
            ->withHeaders([
                'Authorization' => (string) config('services.clickup.token'),
            ])
            ->post("https://api.clickup.com/api/v2/task/{$taskId}/attachment")
            ->throw();
    }

    public function listIdForDepartment(string $department): string
    {
        $key = match (strtolower($department)) {
            'sales', 'المبيعات' => 'sales',
            'design', 'تصميم' => 'design',
            'content', 'محتوى' => 'content',
            'programming', 'web', 'development', 'dev', 'البرمجة', 'برمجة', 'ويب' => 'programming',
            'photography', 'media', 'التصوير', 'تصوير', 'photo' => 'photography',
            default => 'sales',
        };

        $listId = config("services.clickup.lists.{$key}") ?? $this->defaultListId();

        if (! is_string($listId) || $listId === '') {
            return $this->defaultListId();
        }

        return $listId;
    }

    private function defaultListId(): string
    {
        return (string) (config('services.clickup.lists.sales')
            ?? config('services.clickup.list_id')
            ?? '');
    }

    /**
     * @return array<int, array{id: string, name: string, email: string|null}>
     */
    public function listMembers(string $listId): array
    {
        $token = (string) config('services.clickup.token');
        if ($token === '' || $listId === '') {
            return [];
        }

        $response = Http::timeout((int) config('services.clickup.timeout', 12))
            ->connectTimeout(3)
            ->acceptJson()
            ->withHeaders(['Authorization' => $token])
            ->get('https://api.clickup.com/api/v2/list/'.$listId.'/member');

        if (! $response->successful()) {
            return [];
        }

        return $this->mapMembers($response->json('members') ?? []);
    }

    /**
     * @return list<array{id: string, name: string, status: string|null, url: string|null, assignees: list<array{id: string, name: string}>}>
     */
    public function getTasks(string $listId): array
    {
        $token = (string) config('services.clickup.token');
        if ($token === '' || $listId === '') {
            return [];
        }

        try {
            $response = Http::timeout((int) config('services.clickup.timeout', 12))
                ->connectTimeout(3)
                ->acceptJson()
                ->withHeaders(['Authorization' => $token])
                ->get('https://api.clickup.com/api/v2/list/'.$listId.'/task', [
                    'archived' => 'false',
                    'include_closed' => 'false',
                ]);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $tasks = [];
        foreach ($response->json('tasks') ?? [] as $task) {
            if (! is_array($task)) {
                continue;
            }
            $assignees = [];
            foreach ($task['assignees'] ?? [] as $assignee) {
                if (! is_array($assignee)) {
                    continue;
                }
                $id = $assignee['id'] ?? null;
                if ($id === null || $id === '') {
                    continue;
                }
                $assignees[] = [
                    'id' => (string) $id,
                    'name' => (string) ($assignee['username'] ?? $assignee['email'] ?? $id),
                ];
            }
            $priority = $task['priority'] ?? null;
            $tasks[] = [
                'id' => (string) ($task['id'] ?? ''),
                'name' => (string) ($task['name'] ?? ''),
                'status' => is_array($task['status'] ?? null)
                    ? (string) ($task['status']['status'] ?? '')
                    : (isset($task['status']) ? (string) $task['status'] : null),
                'priority' => is_array($priority) ? ($priority['id'] ?? $priority['priority'] ?? null) : $priority,
                'url' => isset($task['url']) ? (string) $task['url'] : null,
                'due_date' => $task['due_date'] ?? null,
                'assignees' => $assignees,
            ];
        }

        return $tasks;
    }

    /**
     * @return list<array{id: string, name: string, status: string|null, url: string|null, due_date: mixed, assignees: list<array{id: string, name: string}>}>
     */
    public function listTasksForList(string $listId): array
    {
        return $this->getTasks($listId);
    }

    /**
     * Best-effort guest invite (ClickUp team guest / shared guest APIs vary by plan).
     *
     * @param  list<string>  $listIds
     * @return array{ok: bool, message: string, raw?: mixed}
     */
    public function inviteGuest(string $email, array $listIds = []): array
    {
        $token = (string) config('services.clickup.token');
        if ($token === '') {
            return ['ok' => false, 'message' => 'ClickUp is not configured.'];
        }

        $email = trim($email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'A valid email is required.'];
        }

        $teamId = $this->resolveTeamId($token);
        if ($teamId === null) {
            return ['ok' => false, 'message' => 'Could not resolve ClickUp team id for guest invite.'];
        }

        $payload = [
            'email' => $email,
            'can_edit_tags' => false,
            'can_see_time_spent' => false,
            'can_see_time_estimated' => false,
            'can_create_views' => false,
        ];
        if ($listIds !== []) {
            $payload['permission_level'] = 'read';
        }

        $response = Http::timeout((int) config('services.clickup.timeout', 12))
            ->connectTimeout(3)
            ->acceptJson()
            ->withHeaders(['Authorization' => $token])
            ->post("https://api.clickup.com/api/v2/team/{$teamId}/guest", $payload);

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'Guest invite sent.', 'raw' => $response->json()];
        }

        // Soft-fail with clear error — plans without guest seats often 403.
        $err = $response->json('err')
            ?? $response->json('ECODE')
            ?? $response->json('message')
            ?? $response->body();

        return [
            'ok' => false,
            'message' => 'ClickUp guest invite failed: '.(is_string($err) ? $err : json_encode($err)),
            'raw' => $response->json(),
        ];
    }

    private function resolveTeamId(string $token): ?string
    {
        $configured = config('services.clickup.team_id') ?? config('services.clickup.space_id');
        if (is_string($configured) && $configured !== '') {
            // Prefer dedicated team_id when set; space_id alone is not always the team id.
        }

        $teamConfigured = config('services.clickup.team_id');
        if (is_string($teamConfigured) && $teamConfigured !== '') {
            return $teamConfigured;
        }

        $response = Http::timeout((int) config('services.clickup.timeout', 12))
            ->connectTimeout(3)
            ->acceptJson()
            ->withHeaders(['Authorization' => $token])
            ->get('https://api.clickup.com/api/v2/team');

        if (! $response->successful()) {
            return null;
        }

        $teams = $response->json('teams') ?? [];
        if (! is_array($teams) || $teams === []) {
            return null;
        }

        $first = $teams[0];

        return is_array($first) && isset($first['id']) ? (string) $first['id'] : null;
    }

    /**
     * @return array<int, array{id: string, name: string, email: string|null}>
     */
    public function members(): array
    {
        $token = (string) config('services.clickup.token');
        if ($token === '') {
            return [];
        }

        $listId = $this->defaultListId();
        if ($listId !== '') {
            $response = Http::timeout((int) config('services.clickup.timeout', 12))
                ->connectTimeout(3)
                ->acceptJson()
                ->withHeaders(['Authorization' => $token])
                ->get('https://api.clickup.com/api/v2/list/'.$listId.'/member');

            if ($response->successful()) {
                return $this->mapMembers($response->json('members') ?? []);
            }
        }

        $response = Http::timeout((int) config('services.clickup.timeout', 12))
            ->connectTimeout(3)
            ->acceptJson()
            ->withHeaders(['Authorization' => $token])
            ->get('https://api.clickup.com/api/v2/team');

        if (! $response->successful()) {
            return [];
        }

        $members = [];
        foreach ($response->json('teams') ?? [] as $team) {
            if (! is_array($team)) {
                continue;
            }
            foreach ($team['members'] ?? [] as $member) {
                if (! is_array($member)) {
                    continue;
                }
                $user = is_array($member['user'] ?? null) ? $member['user'] : $member;
                $mapped = $this->mapMember($user);
                if ($mapped !== null) {
                    $members[$mapped['id']] = $mapped;
                }
            }
        }

        return array_values($members);
    }

    /**
     * @return array<int, array{id: string, name: string, email: string|null}>
     */
    public function membersForList(string $listId): array
    {
        $token = (string) config('services.clickup.token');
        if ($token === '' || $listId === '') {
            return [];
        }

        $response = Http::timeout((int) config('services.clickup.timeout', 12))
            ->connectTimeout(3)
            ->acceptJson()
            ->withHeaders(['Authorization' => $token])
            ->get('https://api.clickup.com/api/v2/list/'.$listId.'/member');

        if (! $response->successful()) {
            return [];
        }

        return $this->mapMembers($response->json('members') ?? []);
    }

    /**
     * @return array<string, string>
     */
    public function departmentLists(): array
    {
        $lists = [];
        foreach (['sales', 'design', 'content', 'programming', 'photography'] as $key) {
            $id = config("services.clickup.lists.{$key}");
            if (is_string($id) && $id !== '') {
                $lists[$key] = $id;
            }
        }

        return $lists;
    }

    /**
     * @param  array<int, mixed>  $members
     * @return array<int, array{id: string, name: string, email: string|null}>
     */
    private function mapMembers(array $members): array
    {
        $mapped = [];
        foreach ($members as $member) {
            if (! is_array($member)) {
                continue;
            }
            $user = is_array($member['user'] ?? null) ? $member['user'] : $member;
            $item = $this->mapMember($user);
            if ($item !== null) {
                $mapped[$item['id']] = $item;
            }
        }

        return array_values($mapped);
    }

    /**
     * @param  array<string, mixed>  $user
     * @return array{id: string, name: string, email: string|null}|null
     */
    private function mapMember(array $user): ?array
    {
        $id = $user['id'] ?? null;
        if ($id === null || $id === '') {
            return null;
        }

        $name = (string) ($user['username'] ?? $user['email'] ?? $id);
        $email = isset($user['email']) && is_string($user['email']) ? $user['email'] : null;

        return [
            'id' => (string) $id,
            'name' => $name !== '' ? $name : (string) $id,
            'email' => $email,
        ];
    }
}
