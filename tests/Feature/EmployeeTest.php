<?php

namespace Tests\Feature;

use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Enums\ExecutionStatus;
use App\Enums\RequestStatus;
use App\Enums\WorkflowEventType;
use App\Models\ClickUpTask;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Quotation;
use App\Models\Revision;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_employees(): void
    {
        $this->getJson('/api/admin/employees')->assertUnauthorized();
    }

    public function test_client_cannot_create_employee(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/admin/employees', [
            'name' => 'Sara',
            'profession' => 'sales',
        ])->assertForbidden();
    }

    public function test_admin_can_create_and_list_employees(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/employees', [
            'name' => 'Sara Saleh',
            'phone' => '+963 999 000 111',
            'telegram_user_id' => '555001',
            'clickup_user_id' => '88821',
            'profession' => 'sales',
            'notes' => 'Morning shift',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Sara Saleh')
            ->assertJsonPath('data.profession', 'sales')
            ->assertJsonPath('data.code', 'EMP-0001');

        $this->getJson('/api/admin/employees')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_employee_name_is_required(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/employees', [
            'profession' => 'sales',
        ])->assertUnprocessable();
    }

    public function test_new_request_notifies_sales_employees(): void
    {
        Http::preventStrayRequests();
        config([
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.telegram.bot_token' => 'client-token',
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        Employee::factory()->sales()->create([
            'telegram_user_id' => '6350001',
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/link', [
                'telegram_user_id' => 'tg-client-1',
                'name' => 'Client One',
                'phone' => '+963900000001',
                'company_name' => 'شركة العميل',
                'locale' => 'ar',
            ])->assertOk();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/requests', [
                'telegram_user_id' => 'tg-client-1',
                'title' => 'Booth for sales',
                'description' => 'Need a quotation.',
            ])->assertCreated();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botstaff-token/sendMessage')
            && $request['chat_id'] === '6350001'
            && str_contains((string) $request['text'], 'طلب جديد لقسم المبيعات'));
    }

    public function test_telegram_submit_accepts_description_attachments(): void
    {
        Http::preventStrayRequests();
        config([
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.telegram.bot_token' => 'client-token',
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        Employee::factory()->sales()->create(['telegram_user_id' => '6350001']);
        $client = Client::factory()->create([
            'telegram_user_id' => 'tg-client-attach',
            'phone' => '+963900000002',
            'company_name' => 'شركة المرفقات',
        ]);

        $png = base64_encode((string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ));

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/requests', [
                'telegram_user_id' => 'tg-client-attach',
                'title' => 'Logo design',
                'description' => 'See attached reference.',
                'attachments' => [[
                    'file_name' => 'reference.png',
                    'file_base64' => $png,
                    'mime_type' => 'image/png',
                ]],
            ])->assertCreated()
            ->assertJsonPath('data.title', 'Logo design');

        $requestId = ServiceRequest::query()->whereHas('client', fn ($q) => $q->where('telegram_user_id', 'tg-client-attach'))->value('id');
        $this->assertDatabaseHas('request_files', [
            'request_id' => $requestId,
            'kind' => 'brief_attachment',
            'original_name' => 'reference.png',
        ]);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botstaff-token/sendMessage')
            && str_contains((string) $request['text'], 'مرفقات: 1'));
    }

    public function test_staff_bot_forwards_a_reply_to_the_client_bot(): void
    {
        Http::preventStrayRequests();
        config(['services.telegram.bot_token' => 'client-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        Employee::factory()->sales()->create([
            'telegram_user_id' => '6350001',
        ]);
        $serviceRequest = ServiceRequest::factory()->create([
            'number' => 'REQ-'.now()->year.'-000012',
        ]);
        $serviceRequest->client?->forceFill(['telegram_user_id' => 'tg-client-9'])->save();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/reply', [
                'telegram_user_id' => '6350001',
                'request_number' => '12',
                'text' => 'سنرسل العرض غداً',
            ])->assertOk()
            ->assertJsonPath('data.sent', true);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botclient-token/sendMessage')
            && $request['chat_id'] === 'tg-client-9'
            && str_contains((string) $request['text'], 'سنرسل العرض غداً')
            && str_contains((string) $request['text'], '#12')
            && isset($request['reply_markup']['inline_keyboard']));
    }

    public function test_staff_replyable_requests_lists_open_requests(): void
    {
        Employee::factory()->sales()->create(['telegram_user_id' => '6350001']);
        $open = ServiceRequest::factory()->create(['status' => RequestStatus::Submitted]);
        ServiceRequest::factory()->create(['status' => RequestStatus::Completed]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->getJson('/api/bot/staff/replyable-requests?telegram_user_id=6350001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $open->number);
    }

    public function test_submit_creates_sales_clickup_task_without_ai(): void
    {
        Http::preventStrayRequests();
        config([
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.telegram.bot_token' => 'client-token',
            'services.clickup.token' => 'pk_test',
            'services.clickup.list_id' => '901821114100',
            'services.clickup.lists.sales' => '901821114100',
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://api.clickup.com/api/v2/list/901821114100/task' => Http::response(['id' => 'cu-sales-intake'], 200),
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        Employee::factory()->sales()->create(['telegram_user_id' => '6350001']);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/link', [
                'telegram_user_id' => 'tg-client-1',
                'name' => 'Client One',
                'phone' => '+963900000001',
                'company_name' => 'شركة العميل',
                'locale' => 'ar',
            ])->assertOk();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/requests', [
                'telegram_user_id' => 'tg-client-1',
                'title' => 'Booth for sales',
                'description' => 'Need a quotation.',
            ])->assertCreated();

        $this->assertDatabaseHas('clickup_tasks', [
            'clickup_task_id' => 'cu-sales-intake',
            'task_type' => 'sales',
        ]);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.clickup.com/api/v2/list/901821114100/task')
            && str_contains((string) $request['name'], 'Booth for sales')
            && str_contains((string) $request['description'], 'Need a quotation.'));
    }

    public function test_sales_staff_can_send_quotation_via_bot_api(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        config([
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.telegram.bot_token' => 'client-token',
        ]);
        Http::fake(array_merge($this->odooDocumentsHttpFake(), [
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['document' => ['file_id' => 'doc-1']]], 200),
        ]));

        Employee::factory()->sales()->create(['telegram_user_id' => '6350001']);
        $client = Client::factory()->create(['telegram_user_id' => 'tg-client-9']);
        $serviceRequest = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Submitted,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/quotation', [
                'telegram_user_id' => '6350001',
                'request_number' => (string) $serviceRequest->number,
                'amount' => 450.5,
                'notes' => 'يشمل التصميم الأولي',
            ])->assertOk()
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('data.quotation_version', 1);

        $serviceRequest->refresh();
        $this->assertSame(RequestStatus::QuotationSent, $serviceRequest->status);
        $this->assertDatabaseHas('quotations', [
            'request_id' => $serviceRequest->id,
            'version' => 1,
            'amount' => 450.5,
        ]);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'botclient-token/sendDocument')) {
                return false;
            }

            return collect($request->data())->contains(function (mixed $value): bool {
                $text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);

                return is_string($text) && str_contains($text, 'موافقة') && str_contains($text, 'رفض');
            });
        });
        Http::assertNotSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'botclient-token/sendMessage')) {
                return false;
            }

            $text = (string) ($request->data()['text'] ?? $request->data()['caption'] ?? '');

            return str_contains($text, 'تم إنشاء الطلب')
                || str_contains($text, 'وإرسال عرض السعر')
                || $text === 'اختر:';
        });
    }

    public function test_non_sales_staff_cannot_send_quotation(): void
    {
        Employee::factory()->create([
            'telegram_user_id' => '6350002',
            'profession' => EmployeeProfession::Design,
        ]);
        $serviceRequest = ServiceRequest::factory()->create([
            'status' => RequestStatus::Submitted,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->getJson('/api/bot/staff/quotable-requests?telegram_user_id=6350002')
            ->assertForbidden();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/quotation', [
                'telegram_user_id' => '6350002',
                'request_number' => $serviceRequest->number,
                'amount' => 100,
            ])->assertForbidden();
    }

    public function test_client_can_acknowledge_and_cancel_from_telegram(): void
    {
        Http::preventStrayRequests();
        config(['services.telegram.staff_bot_token' => 'staff-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        Employee::factory()->sales()->create(['telegram_user_id' => '6350001']);
        $client = Client::factory()->create(['telegram_user_id' => 'tg-client-9']);
        $serviceRequest = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Submitted,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$serviceRequest->number}/acknowledge", [
                'telegram_user_id' => 'tg-client-9',
            ])->assertOk()
            ->assertJsonPath('data.acknowledged', true);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botstaff-token/sendMessage')
            && $request['chat_id'] === '6350001'
            && str_contains((string) $request['text'], 'أكد الزبون اهتمامه'));

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$serviceRequest->number}/cancel", [
                'telegram_user_id' => 'tg-client-9',
            ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botstaff-token/sendMessage')
            && $request['chat_id'] === '6350001'
            && str_contains((string) $request['text'], 'ألغى الزبون الطلب'));
    }

    public function test_quotation_approve_and_reject_notify_sales(): void
    {
        Http::preventStrayRequests();
        config([
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.gemini.e2e_stub' => true,
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        Employee::factory()->sales()->create(['telegram_user_id' => '6350001']);
        $client = Client::factory()->create(['telegram_user_id' => '213309826']);
        $serviceRequest = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::QuotationSent,
        ]);
        Quotation::query()->create([
            'request_id' => $serviceRequest->id,
            'version' => 1,
            'amount' => 250,
            'sent_at' => now(),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$serviceRequest->number}/approve", [
                'telegram_user_id' => '213309826',
            ])->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botstaff-token/sendMessage')
            && $request['chat_id'] === '6350001'
            && str_contains((string) $request['text'], 'وافق الزبون على عرض السعر')
            && str_contains((string) $request['text'], 'tg://user?id=213309826'));

        $rejected = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::QuotationSent,
        ]);
        Quotation::query()->create([
            'request_id' => $rejected->id,
            'version' => 1,
            'amount' => 300,
            'sent_at' => now(),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$rejected->number}/reject", [
                'telegram_user_id' => '213309826',
                'reason' => 'السعر مرتفع',
            ])->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botstaff-token/sendMessage')
            && $request['chat_id'] === '6350001'
            && str_contains((string) $request['text'], 'رفض الزبون عرض السعر')
            && str_contains((string) $request['text'], 'السعر مرتفع')
            && str_contains((string) $request['text'], 'tg://user?id=213309826'));
    }

    public function test_receipt_upload_notifies_sales(): void
    {
        Http::preventStrayRequests();
        config([
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.gemini.e2e_stub' => true,
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        Employee::factory()->sales()->create(['telegram_user_id' => '6350001']);
        $client = Client::factory()->create(['telegram_user_id' => 'tg-client-9']);
        $serviceRequest = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::AwaitingPayment,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$serviceRequest->number}/receipt", [
                'telegram_user_id' => 'tg-client-9',
                'file_name' => 'receipt.jpg',
                'file_base64' => base64_encode('fake-image'),
                'mime_type' => 'image/jpeg',
            ])->assertOk()
            ->assertJsonPath('data.stored', true);

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return str_contains($request->url(), 'botstaff-token/sendPhoto')
                && str_contains($body, '6350001')
                && str_contains($body, 'رفع الزبون وصل دفع');
        });
    }

    public function test_receipt_upload_sends_pdf_as_document_to_sales(): void
    {
        Http::preventStrayRequests();
        config([
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.gemini.e2e_stub' => true,
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        Employee::factory()->sales()->create(['telegram_user_id' => '6350001']);
        $client = Client::factory()->create(['telegram_user_id' => 'tg-client-9']);
        $serviceRequest = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::AwaitingPayment,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$serviceRequest->number}/receipt", [
                'telegram_user_id' => 'tg-client-9',
                'file_name' => 'receipt.pdf',
                'file_base64' => base64_encode('%PDF-1.4 fake'),
                'mime_type' => 'application/pdf',
            ])->assertOk();

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return str_contains($request->url(), 'botstaff-token/sendDocument')
                && str_contains($body, '6350001');
        });
    }

    public function test_unknown_staff_cannot_reply(): void
    {
        $serviceRequest = ServiceRequest::factory()->create();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/reply', [
                'telegram_user_id' => 'not-on-file',
                'request_number' => $serviceRequest->number,
                'text' => 'Hello',
            ])->assertNotFound();
    }

    public function test_admin_can_update_and_delete_an_employee(): void
    {
        Sanctum::actingAs($this->admin());
        $employee = Employee::factory()->create(['profession' => EmployeeProfession::Media]);

        $this->putJson("/api/admin/employees/{$employee->id}", [
            'name' => 'Updated Name',
            'profession' => 'web',
        ])->assertOk()->assertJsonPath('data.profession', 'web');

        $this->deleteJson("/api/admin/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Employee deleted from dashboard and Odoo.');
    }

    public function test_staff_can_join_without_typing_ids(): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/join', [
                'telegram_user_id' => '9001',
                'name' => 'Nour Saleh',
                'telegram_username' => 'nour',
            ])->assertCreated()
            ->assertJsonPath('data.name', 'Nour Saleh')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.telegram_user_id', '9001')
            ->assertJsonPath('data.telegram_username', 'nour');

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/join', [
                'telegram_user_id' => '9001',
                'name' => 'Nour Saleh',
                'telegram_username' => 'nour',
            ])->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('employees', 1);
    }

    public function test_staff_join_allocates_next_free_employee_code(): void
    {
        Employee::factory()->create(['code' => 'EMP-0003']);
        Employee::factory()->create(['code' => 'EMP-0001']);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/join', [
                'telegram_user_id' => '9002',
                'name' => 'New Staff',
            ])->assertCreated()
            ->assertJsonPath('data.code', 'EMP-0004');
    }

    public function test_pending_staff_cannot_reply(): void
    {
        Employee::factory()->pending()->create([
            'telegram_user_id' => '9001',
        ]);
        $serviceRequest = ServiceRequest::factory()->create();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/reply', [
                'telegram_user_id' => '9001',
                'request_number' => $serviceRequest->number,
                'text' => 'Hello',
            ])->assertNotFound();
    }

    public function test_admin_can_approve_a_join_request_with_clickup_member(): void
    {
        Sanctum::actingAs($this->admin());
        $employee = Employee::factory()->pending()->create([
            'telegram_user_id' => '9001',
            'name' => 'Nour Saleh',
        ]);

        $this->postJson("/api/admin/employees/{$employee->id}/approve", [
            'profession' => 'web',
            'clickup_user_id' => 'cu-42',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.profession', 'web')
            ->assertJsonPath('data.clickup_user_id', 'cu-42');
    }

    public function test_admin_can_reject_a_join_request_and_staff_can_reapply(): void
    {
        Sanctum::actingAs($this->admin());
        $employee = Employee::factory()->pending()->create([
            'telegram_user_id' => '9001',
        ]);

        $this->postJson("/api/admin/employees/{$employee->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/join', [
                'telegram_user_id' => '9001',
                'name' => 'Nour Saleh',
                'telegram_username' => 'nour',
            ])->assertOk()
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_admin_can_list_clickup_members_by_name(): void
    {
        Sanctum::actingAs($this->admin());
        config([
            'services.clickup.token' => 'pk_test',
            'services.clickup.list_id' => '12345',
            'services.clickup.lists.sales' => '12345',
        ]);
        Http::fake([
            'https://api.clickup.com/api/v2/list/12345/member' => Http::response([
                'members' => [
                    ['id' => 42, 'username' => 'Sara Design', 'email' => 'sara@example.com'],
                ],
            ], 200),
        ]);

        $this->getJson('/api/admin/clickup/members')
            ->assertOk()
            ->assertJsonPath('data.0.id', '42')
            ->assertJsonPath('data.0.name', 'Sara Design');
    }

    public function test_employee_email_follows_the_clickup_member(): void
    {
        Sanctum::actingAs($this->admin());
        config([
            'services.clickup.token' => 'pk_test',
            'services.clickup.list_id' => '12345',
            'services.clickup.lists.sales' => '12345',
        ]);
        Http::fake([
            'https://api.clickup.com/api/v2/list/12345/member' => Http::response([
                'members' => [
                    ['id' => 42, 'username' => 'Sara Design', 'email' => 'sara@example.com'],
                ],
            ], 200),
        ]);

        $this->postJson('/api/admin/employees', [
            'name' => 'Sara Saleh',
            'email' => 'other@example.com',
            'clickup_user_id' => '42',
            'profession' => 'sales',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'sara@example.com');
    }

    public function test_guest_cannot_list_clickup_members(): void
    {
        $this->getJson('/api/admin/clickup/members')->assertUnauthorized();
    }

    public function test_non_sales_cannot_see_other_staff_tasks_or_intake(): void
    {
        $designer = Employee::factory()->create([
            'telegram_user_id' => '6350002',
            'profession' => EmployeeProfession::Design,
        ]);
        $other = Employee::factory()->create([
            'telegram_user_id' => '6350003',
            'profession' => EmployeeProfession::Web,
        ]);
        $mine = ServiceRequest::factory()->create([
            'status' => RequestStatus::InProgress,
            'title' => 'My booth',
        ]);
        $theirs = ServiceRequest::factory()->create([
            'status' => RequestStatus::InProgress,
            'title' => 'Other booth',
        ]);
        $intake = ServiceRequest::factory()->create([
            'status' => RequestStatus::Submitted,
        ]);
        $this->assignClickUpTask($mine, $designer);
        $this->assignClickUpTask($theirs, $other);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->getJson('/api/bot/staff/tasks?telegram_user_id=6350002')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'My booth');

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->getJson('/api/bot/staff/new-requests?telegram_user_id=6350002')
            ->assertForbidden();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->getJson('/api/bot/staff/replyable-requests?telegram_user_id=6350002')
            ->assertForbidden();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/reply', [
                'telegram_user_id' => '6350002',
                'request_number' => (string) $intake->number,
                'text' => 'hello',
            ])->assertForbidden();
    }

    public function test_non_sales_can_deliver_assigned_task_and_revision(): void
    {
        Http::preventStrayRequests();
        config(['services.telegram.bot_token' => 'client-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $designer = Employee::factory()->create([
            'telegram_user_id' => '6350002',
            'profession' => EmployeeProfession::Design,
        ]);
        $client = Client::factory()->create(['telegram_user_id' => 'tg-client-9']);
        $mine = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
        ]);
        $foreign = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
        ]);
        $this->assignClickUpTask($mine, $designer);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/deliver', [
                'telegram_user_id' => '6350002',
                'request_number' => (string) $foreign->number,
                'notes' => 'not mine',
            ])->assertForbidden();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/deliver', [
                'telegram_user_id' => '6350002',
                'request_number' => (string) $mine->number,
                'notes' => 'first delivery',
            ])->assertOk()
            ->assertJsonPath('data.status', 'ready_for_review');

        $this->assertDatabaseHas('integration_events', [
            'request_uuid' => $mine->uuid,
            'event_type' => WorkflowEventType::DeliveryReady->value,
        ]);

        $mine->forceFill(['status' => RequestStatus::RevisionRequested])->save();
        Revision::query()->create([
            'request_id' => $mine->id,
            'comments' => 'غيّر اللون إلى الأبيض',
            'status' => 'open',
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->getJson('/api/bot/staff/tasks?telegram_user_id=6350002')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'revision_requested')
            ->assertJsonPath('data.0.revision_comments', 'غيّر اللون إلى الأبيض');

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/deliver', [
                'telegram_user_id' => '6350002',
                'request_number' => (string) $mine->number,
                'notes' => 'revised files',
            ])->assertOk()
            ->assertJsonPath('data.status', 'ready_for_review');
    }

    public function test_staff_reply_moves_sales_clickup_task_to_progress_and_assigns_employee(): void
    {
        Http::preventStrayRequests();
        config([
            'services.telegram.bot_token' => 'client-token',
            'services.clickup.token' => 'pk_test',
            'services.clickup.statuses.progress' => 'in progress',
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://api.clickup.com/api/v2/task/cu-sales-9' => Http::response(['id' => 'cu-sales-9'], 200),
            'https://api.clickup.com/api/v2/task/cu-sales-9/comment' => Http::response(['id' => 1], 200),
        ]);

        $employee = Employee::factory()->sales()->create([
            'telegram_user_id' => '6350001',
            'name' => 'Sara Sales',
            'clickup_user_id' => '88821',
        ]);
        $serviceRequest = ServiceRequest::factory()->create([
            'number' => 'REQ-'.now()->year.'-000019',
        ]);
        $serviceRequest->client?->forceFill(['telegram_user_id' => 'tg-client-9'])->save();
        ClickUpTask::query()->create([
            'request_id' => $serviceRequest->id,
            'task_type' => ClickUpTaskType::Sales,
            'clickup_task_id' => 'cu-sales-9',
            'integration_key' => ClickUpTask::buildIntegrationKey($serviceRequest->uuid, null, ClickUpTaskType::Sales),
            'status' => 'to do',
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/reply', [
                'telegram_user_id' => '6350001',
                'request_number' => '19',
                'text' => 'تم استلام طلبك',
            ])->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/api/v2/task/cu-sales-9')
            && $request['status'] === 'in progress'
            && $request['assignees']['add'] === [88821]);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v2/task/cu-sales-9/comment')
            && str_contains((string) $request['comment_text'], 'Sara Sales')
            && str_contains((string) $request['comment_text'], 'تم استلام طلبك'));

        $this->assertDatabaseHas('clickup_tasks', [
            'clickup_task_id' => 'cu-sales-9',
            'status' => 'in progress',
            'employee_id' => $employee->id,
            'clickup_user_id' => '88821',
        ]);
        $this->assertSame(ExecutionStatus::InProgress, $serviceRequest->fresh()?->execution_status);
    }

    public function test_completing_a_request_marks_clickup_tasks_complete(): void
    {
        Http::preventStrayRequests();
        config([
            'services.clickup.token' => 'pk_test',
            'services.clickup.statuses.complete' => 'complete',
        ]);
        Http::fake([
            'https://api.clickup.com/api/v2/task/cu-sales-done' => Http::response(['id' => 'cu-sales-done'], 200),
            'https://api.clickup.com/api/v2/task/cu-sales-done/comment' => Http::response(['id' => 1], 200),
        ]);

        $employee = Employee::factory()->sales()->create([
            'telegram_user_id' => '6350001',
            'clickup_user_id' => '88821',
        ]);
        $serviceRequest = ServiceRequest::factory()->create([
            'status' => RequestStatus::ReadyForReview,
            'number' => 'REQ-'.now()->year.'-000021',
        ]);
        ClickUpTask::query()->create([
            'request_id' => $serviceRequest->id,
            'task_type' => ClickUpTaskType::Sales,
            'clickup_task_id' => 'cu-sales-done',
            'integration_key' => ClickUpTask::buildIntegrationKey($serviceRequest->uuid, null, ClickUpTaskType::Sales),
            'status' => 'review',
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/complete', [
                'telegram_user_id' => '6350001',
                'request_number' => '21',
            ])->assertOk()
            ->assertJsonPath('data.status', 'completed');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/api/v2/task/cu-sales-done')
            && $request['status'] === 'complete');

        $this->assertSame(ExecutionStatus::Completed, $serviceRequest->fresh()?->execution_status);
        $this->assertDatabaseHas('clickup_tasks', [
            'clickup_task_id' => 'cu-sales-done',
            'status' => 'complete',
            'employee_id' => $employee->id,
        ]);
    }

    private function assignClickUpTask(ServiceRequest $request, Employee $employee): void
    {
        ClickUpTask::query()->create([
            'request_id' => $request->id,
            'task_type' => ClickUpTaskType::Design,
            'employee_id' => $employee->id,
            'integration_key' => $request->uuid.':0:design:'.$employee->id,
            'clickup_task_id' => 'cu-'.$employee->id,
            'clickup_url' => 'https://app.clickup.com/t/cu-'.$employee->id,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }
}
