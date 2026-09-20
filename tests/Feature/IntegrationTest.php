<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\OpsSetting;
use App\Models\ServiceRequest;
use App\Services\GoogleDriveClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_odoo_quotation_returns_placeholders_when_not_configured(): void
    {
        $number = $this->createTelegramRequest();

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/odoo/quotation', [
                'request_number' => $number,
                'title' => 'Booth identity',
            ])->assertOk()
            ->assertJsonPath('data.odoo_quotation_id', 'Q-'.$number)
            ->assertJsonPath('data.odoo_partner_id', 'P-'.$number);
    }

    public function test_odoo_stays_off_even_when_credentials_are_present(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => false,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
        ]);

        $number = $this->createTelegramRequest();

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/odoo/quotation', [
                'request_number' => $number,
                'title' => 'Booth identity',
            ])->assertOk()
            ->assertJsonPath('data.odoo_quotation_id', 'Q-'.$number);

        Http::assertNothingSent();
    }

    public function test_odoo_quotation_creates_partner_and_order(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => false,
        ]);

        Http::fake([
            'https://odoo.test/jsonrpc' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => []], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => []], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 4, 'result' => 44], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 5, 'result' => [['id' => 1, 'name' => 'SYP']]], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 6, 'result' => 88], 200),
        ]);

        $number = $this->createTelegramRequest();

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/odoo/quotation', [
                'request_number' => $number,
                'title' => 'Booth identity',
                'client' => ['name' => 'Ammar', 'email' => 'ammar@example.com'],
            ])->assertOk()
            ->assertJsonPath('data.odoo_partner_id', '44')
            ->assertJsonPath('data.odoo_quotation_id', '88');

        Http::assertSentCount(6);
    }

    public function test_clickup_programming_tasks_use_programming_list(): void
    {
        Http::preventStrayRequests();
        config([
            'services.clickup.token' => 'pk_test',
            'services.clickup.list_id' => '12345',
            'services.clickup.lists.sales' => '12345',
            'services.clickup.lists.programming' => 'prog-list',
        ]);

        Http::fake([
            'https://api.clickup.com/api/v2/list/prog-list/task' => Http::response(['id' => 'cu-prog'], 200),
        ]);

        $number = $this->createTelegramRequest();

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/clickup/tasks', [
                'request_number' => $number,
                'briefs' => [
                    ['department' => 'programming', 'brief' => 'Build landing page'],
                ],
            ])->assertOk()
            ->assertJsonPath('data.briefs.0.clickup_task_id', 'cu-prog');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/list/prog-list/task'));
    }

    public function test_clickup_tasks_are_created_per_department(): void
    {
        Http::preventStrayRequests();
        config([
            'services.clickup.token' => 'pk_test',
            'services.clickup.list_id' => '12345',
            'services.clickup.lists.sales' => '12345',
            'services.clickup.lists.design' => '12345',
            'services.clickup.lists.content' => '12345',
            'services.clickup.lists.photography' => '12345',
        ]);

        Http::fake([
            'https://api.clickup.com/api/v2/list/12345/task' => Http::sequence()
                ->push(['id' => 'cu-brand'], 200)
                ->push(['id' => 'cu-3d'], 200),
        ]);

        $number = $this->createTelegramRequest();

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/clickup/tasks', [
                'request_number' => $number,
                'briefs' => [
                    ['department' => 'branding', 'brief' => 'Identity'],
                    ['department' => '3d_visualization', 'brief' => 'Booth'],
                ],
            ])->assertOk()
            ->assertJsonPath('data.briefs.0.clickup_task_id', 'cu-brand')
            ->assertJsonPath('data.briefs.1.clickup_task_id', 'cu-3d');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'pk_test')
            && str_contains((string) $request['name'], $number));
    }

    public function test_odoo_invoice_uses_existing_partner(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => false,
        ]);

        Http::fake([
            'https://odoo.test/jsonrpc' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => [['id' => 1, 'name' => 'SYP']]], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => 501], 200),
        ]);

        $number = $this->createTelegramRequest();

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/odoo/invoice', [
                'request_number' => $number,
                'odoo_partner_id' => '44',
                'odoo_quotation_id' => '88',
            ])->assertOk()
            ->assertJsonPath('data.odoo_invoice_id', '501');
    }

    public function test_telegram_notify_uses_the_client_conversation(): void
    {
        Http::preventStrayRequests();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.staff_chat_id' => '6353798919',
        ]);

        Http::fake([
            'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true], 200),
        ]);

        $number = $this->createTelegramRequest();

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/telegram/notify', [
                'request_number' => $number,
                'text' => 'تم استلام طلبك بنجاح بدء العمل عليها',
            ])->assertOk()
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('data.chat_id', 'tg-int-1');

        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === 'tg-int-1'
            && $request['text'] === 'تم استلام طلبك بنجاح بدء العمل عليها');
    }

    public function test_telegram_notify_falls_back_to_the_configured_chat_id(): void
    {
        Http::preventStrayRequests();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.staff_chat_id' => '6353798919',
        ]);

        Http::fake([
            'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true], 200),
        ]);

        $client = Client::factory()->create(['telegram_user_id' => null]);
        $number = ServiceRequest::factory()->create(['client_id' => $client->id])->number;

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/telegram/notify', [
                'request_number' => $number,
                'text' => 'تم استلام طلبك بنجاح بدء العمل عليها',
            ])->assertOk()
            ->assertJsonPath('data.chat_id', '6353798919');
    }

    public function test_drive_poll_requires_secret(): void
    {
        $this->withHeaders(['X-N8N-Secret' => 'wrong'])
            ->postJson('/api/integrations/drive/poll')
            ->assertUnauthorized();
    }

    public function test_drive_poll_scopes_to_folder_id(): void
    {
        $number = $this->createTelegramRequest();
        ServiceRequest::query()->where('number', $number)->update([
            'google_drive_folder_id' => 'folder-req-1',
        ]);

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/drive/poll', [
                'drive_folder_id' => 'folder-req-1',
                'drive_file_id' => 'file-99',
                'drive_file_name' => 'logo.png',
            ])->assertOk()
            ->assertJsonPath('data.polled', true)
            ->assertJsonPath('data.scoped', true)
            ->assertJsonPath('data.request_number', $number)
            ->assertJsonPath('data.drive_folder_id', 'folder-req-1')
            ->assertJsonPath('data.drive_file_id', 'file-99');
    }

    public function test_drive_change_webhook_requires_watch_token(): void
    {
        $this->postJson('/api/integrations/drive/changed')->assertUnauthorized();
    }

    public function test_drive_change_webhook_polls_on_file_notification(): void
    {
        OpsSetting::setValue('drive_watch_token', 'watch-token');

        $this->withHeaders(['X-Goog-Channel-Token' => 'watch-token', 'X-Goog-Resource-State' => 'change'])
            ->postJson('/api/integrations/drive/changed', ['drive_file_id' => 'file-new'])
            ->assertOk()
            ->assertJsonPath('data.polled', true)
            ->assertJsonPath('data.drive_file_id', 'file-new');
    }

    public function test_drive_poll_without_match_scans_live_folders(): void
    {
        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/drive/poll', [])
            ->assertOk()
            ->assertJsonPath('data.polled', true)
            ->assertJsonPath('data.scoped', false)
            ->assertJsonPath('data.request_number', null);
    }

    public function test_drive_poll_ignores_folder_outside_hoc_client(): void
    {
        config(['services.google.drive_parent_folder_id' => 'root-hoc']);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('fileMeta')->with('some-other-drive-folder')->andReturn([
                'id' => 'some-other-drive-folder',
                'parents' => ['someone-else'],
            ]);
            $mock->shouldReceive('isUnderParentFolder')->andReturn(false);
        });

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/drive/poll', [
                'drive_folder_id' => 'some-other-drive-folder',
            ])->assertOk()
            ->assertJsonPath('data.polled', false)
            ->assertJsonPath('data.drive_folder_id', 'some-other-drive-folder');
    }

    public function test_drive_poll_scopes_company_folder_to_the_request_inside_it(): void
    {
        config(['services.google.drive_parent_folder_id' => 'root-hoc']);
        $client = Client::factory()->create(['telegram_user_id' => 'tg-folder']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
            'google_drive_folder_id' => 'task-folder',
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('configurationError')->andReturn(null);
            $mock->shouldReceive('parentId')->with('task-folder')->andReturn('company-folder');
            $mock->shouldReceive('listNewFiles')->andReturn([]);
        });

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/drive/poll', [
                'drive_folder_id' => 'company-folder',
            ])->assertOk()
            ->assertJsonPath('data.polled', true)
            ->assertJsonPath('data.scoped', true)
            ->assertJsonPath('data.request_number', $request->number);
    }

    public function test_drive_poll_runs_for_a_new_folder_under_hoc_client(): void
    {
        config(['services.google.drive_parent_folder_id' => 'root-hoc']);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('configurationError')->andReturn(null);
            $mock->shouldReceive('fileMeta')->with('new-company')->andReturn([
                'id' => 'new-company',
                'parents' => ['root-hoc'],
            ]);
            $mock->shouldReceive('isUnderParentFolder')->andReturn(true);
            $mock->shouldReceive('listNewFiles')->andReturn([]);
        });

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/drive/poll', [
                'drive_folder_id' => 'new-company',
            ])->assertOk()
            ->assertJsonPath('data.polled', true)
            ->assertJsonPath('data.scoped', false);
    }

    public function test_drive_change_webhook_sends_changed_files_from_google(): void
    {
        OpsSetting::setValue('drive_watch_token', 'watch-token');
        OpsSetting::setValue('drive_watch_page_token', 'page-1');

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('configurationError')->andReturn(null);
            $mock->shouldReceive('listChanges')->with('page-1')->andReturn([
                'newPageToken' => 'page-2',
                'files' => [[
                    'id' => 'file-nested',
                    'name' => 'logo.png',
                    'mimeType' => 'image/png',
                    'parents' => ['task-folder'],
                ]],
            ]);
            $mock->shouldReceive('isUnderParentFolder')->andReturn(true);
            $mock->shouldReceive('fileMeta')->andReturn(null);
            $mock->shouldReceive('listNewFiles')->andReturn([]);
        });

        $this->withHeaders(['X-Goog-Channel-Token' => 'watch-token', 'X-Goog-Resource-State' => 'change'])
            ->postJson('/api/integrations/drive/changed')
            ->assertOk()
            ->assertJsonPath('data.polled', true)
            ->assertJsonPath('data.drive_file_id', 'file-nested')
            ->assertJsonPath('data.fallback', false);

        $this->assertSame('page-2', OpsSetting::getValue('drive_watch_page_token'));
    }

    public function test_integrations_reject_a_bad_secret(): void
    {
        $number = $this->createTelegramRequest();

        $this->withHeaders(['X-N8N-Secret' => 'wrong'])
            ->postJson('/api/integrations/odoo/quotation', [
                'request_number' => $number,
                'title' => 'Booth',
            ])->assertUnauthorized();
    }

    private function createTelegramRequest(): string
    {
        $client = Client::factory()->create([
            'telegram_user_id' => 'tg-int-1',
            'name' => 'Integration Client',
        ]);

        return ServiceRequest::factory()->create([
            'client_id' => $client->id,
        ])->number;
    }
}
