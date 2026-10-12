<?php

namespace Tests\Feature;

use App\Enums\StaffAbility;
use App\Models\Client;
use App\Models\Complaint;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComplaintTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.transport' => 'web',
            'services.whatsapp.enabled' => false,
            'services.whatsapp.web_url' => 'http://wa-web.test',
            'services.whatsapp.web_secret' => 'web-secret',
        ]);
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake([
            'http://wa-web.test/*' => Http::response(['id' => 'wa-web-1'], 200),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-12 22:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_staff_answer_a_complaint_on_whatsapp_and_the_client_replies_on_the_ticket(): void
    {
        $phone = '963944000077';
        $this->send($phone, 'a1', 'شكوى');
        $this->send($phone, 'a2', 'تأخير التسليم');
        $this->send($phone, 'a3', 'تأخر تسليم العمل');
        $this->send($phone, 'a4', 'تم');
        $complaint = Complaint::query()->firstOrFail();
        $this->assertNotNull($complaint->chat_id);

        Sanctum::actingAs($this->complaintsStaff());
        $this->getJson("/api/admin/complaints/{$complaint->id}")
            ->assertOk()
            ->assertJsonPath('data.channels.bot', 'whatsapp')
            ->assertJsonPath('data.ticket_number', null);

        $this->postJson("/api/admin/complaints/{$complaint->id}/replies", [
            'body' => 'نعتذر، رح نسلم بكرا.',
            'channel' => 'bot',
        ])->assertCreated()
            ->assertJsonPath('data.status', Complaint::IN_PROGRESS)
            ->assertJsonPath('data.ticket_number', 'T-'.str_pad((string) $complaint->id, 5, '0', STR_PAD_LEFT))
            ->assertJsonPath('data.messages.0.author', 'staff')
            ->assertJsonPath('data.messages.0.channel', 'whatsapp')
            ->assertJsonPath('data.messages.0.delivered', true);
        Http::assertSent(fn (Request $request): bool => str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'نعتذر، رح نسلم بكرا.')
            && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'tkt:'.$complaint->id));

        $this->patchJson("/api/admin/complaints/{$complaint->id}", ['status' => 'resolved'])->assertOk();

        $this->sendButton($phone, 'a5', 'tkt:'.$complaint->id);
        $this->send($phone, 'a6', 'تمام شكراً، بس بدي الملفات كاملة');

        $fresh = $complaint->fresh();
        $this->assertSame(Complaint::IN_PROGRESS, $fresh->status);
        $this->assertSame(2, $fresh->messages()->count());
        $this->assertSame('client', $fresh->messages()->latest('id')->first()->author);
        $this->assertSame('تمام شكراً، بس بدي الملفات كاملة', $fresh->messages()->latest('id')->first()->body);

        // The next message is a normal chat again.
        $this->send($phone, 'a7', 'شكراً');
        $this->assertSame(2, $fresh->messages()->count());
    }

    public function test_an_email_reply_and_an_internal_note_close_the_ticket(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['email' => 'owner@example.com', 'telegram_user_id' => null]);
        $complaint = Complaint::query()->create([
            'client_id' => $client->id,
            'title' => 'جودة الصور',
            'description' => 'الصور غير واضحة',
            'status' => 'open',
        ]);
        Sanctum::actingAs($this->complaintsStaff());

        $this->getJson("/api/admin/complaints/{$complaint->id}")
            ->assertJsonPath('data.channels.bot', null)
            ->assertJsonPath('data.channels.email', 'owner@example.com');
        $this->postJson("/api/admin/complaints/{$complaint->id}/replies", ['body' => 'وصلت', 'channel' => 'bot'])
            ->assertStatus(422);

        $this->postJson("/api/admin/complaints/{$complaint->id}/replies", [
            'body' => 'رح نعيد التصوير الأسبوع الجاي.',
            'channel' => 'email',
        ])->assertCreated()->assertJsonPath('data.messages.0.channel', 'email');

        $this->postJson("/api/admin/complaints/{$complaint->id}/replies", [
            'body' => 'تم التنسيق مع المصور.',
            'channel' => 'note',
            'resolve' => true,
        ])->assertCreated()
            ->assertJsonPath('data.status', Complaint::RESOLVED)
            ->assertJsonPath('data.messages.1.channel', 'note');

        $this->getJson('/api/admin/complaints')
            ->assertJsonPath('data.0.messages_count', 2)
            ->assertJsonPath('data.0.status', Complaint::RESOLVED);
    }

    private function complaintsStaff(): User
    {
        $role = Role::query()->create(['name' => 'الشكاوى', 'abilities' => [StaffAbility::OpsComplaints->value]]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function send(string $phone, string $id, string $text): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', ['phone' => $phone, 'message_id' => $id, 'text' => $text])
            ->assertOk();
    }

    private function sendButton(string $phone, string $id, string $button): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', ['phone' => $phone, 'message_id' => $id, 'button_id' => $button])
            ->assertOk();
    }
}
