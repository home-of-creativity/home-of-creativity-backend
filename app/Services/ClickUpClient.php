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
     * @param  array<int, array{department: string, brief: string}>  $briefs
     * @return array<int, array{department: string, brief: string, clickup_task_id: string}>
     */
    public function createTasks(string $requestNumber, array $briefs): array
    {
        $created = [];

        foreach ($briefs as $brief) {
            $listId = $this->listIdForDepartment((string) $brief['department']);
            $response = Http::timeout((int) config('services.clickup.timeout', 12))
                ->connectTimeout(3)
                ->retry(2, 200)
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => (string) config('services.clickup.token'),
                ])
                ->post('https://api.clickup.com/api/v2/list/'.$listId.'/task', [
                    'name' => $requestNumber.' · '.$brief['department'],
                    'description' => $brief['brief'],
                    'tags' => ['hoc', $brief['department']],
                ])
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

    public function updateTask(string $taskId, ?string $status = null, ?string $assigneeId = null): void
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
