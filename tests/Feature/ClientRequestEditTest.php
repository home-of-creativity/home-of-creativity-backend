<?php

namespace Tests\Feature;

use App\Enums\ClickUpTaskType;
use App\Enums\RequestStatus;
use App\Models\ClickUpTask;
use App\Models\Client;
use App\Models\OdooLeadNote;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A client's edit from the bot reaches the dashboard, the Odoo opportunity, and the ClickUp sales task. */
class ClientRequestEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_telegram_edit_is_noted_in_odoo_and_rewrites_the_sales_task(): void
    {
        config(['services.clickup.token' => 'pk_test', 'services.clickup.lists.sales' => 'list-sales']);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $client = Client::factory()->create(['telegram_user_id' => 'tg-edit']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Submitted,
            'title' => 'شعار',
            'description' => 'شعار لمطعم',
        ]);
        ClickUpTask::query()->create([
            'request_id' => $request->id,
            'task_type' => ClickUpTaskType::Sales,
            'clickup_task_id' => 'task-sales',
            'integration_key' => 'sales-edit',
            'status' => 'to do',
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot', 'Accept' => 'application/json'])
            ->patchJson("/api/bot/telegram/requests/{$request->id}", [
                'telegram_user_id' => 'tg-edit',
                'title' => 'هوية بصرية',
                'description' => 'شعار وألوان لمطعم شاورما',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'هوية بصرية');

        $note = OdooLeadNote::query()->where('request_id', $request->id)->latest('id')->firstOrFail();
        $this->assertStringContainsString('عدّل الزبون الطلب', $note->body);
        $this->assertStringContainsString('العنوان السابق: شعار', $note->body);
        $this->assertStringContainsString('شعار وألوان لمطعم شاورما', $note->body);
        Http::assertSent(fn (Request $sent): bool => $sent->method() === 'PUT'
            && str_ends_with($sent->url(), '/task/task-sales')
            && str_contains((string) ($sent['name'] ?? ''), 'هوية بصرية'));
    }

    public function test_an_unchanged_edit_leaves_odoo_alone(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $client = Client::factory()->create(['telegram_user_id' => 'tg-same']);
        $request = ServiceRequest::factory()->for($client)->create(['status' => RequestStatus::Submitted, 'title' => 'شعار']);
        $before = OdooLeadNote::query()->count();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot', 'Accept' => 'application/json'])
            ->patchJson("/api/bot/telegram/requests/{$request->id}", ['telegram_user_id' => 'tg-same', 'title' => 'شعار'])
            ->assertOk();

        $this->assertSame($before, OdooLeadNote::query()->count());
    }
}
