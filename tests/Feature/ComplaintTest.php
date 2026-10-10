<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComplaintTest extends TestCase
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
        Carbon::setTestNow(Carbon::parse('2026-10-10 22:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_client_files_a_complaint_with_a_photo_and_a_voice_note(): void
    {
        $phone = '963944000099';
        $this->send($phone, 'c1', 'شكوى');
        $this->send($phone, 'c2', 'تأخير التسليم');
        $this->send($phone, 'c3', 'تأخر تسليم العمل أسبوع');
        $this->sendMedia($phone, 'c4', 'image', 'image/jpeg', 'photo.jpg', base64_encode("\xFF\xD8\xFF\xD9"));
        $this->sendMedia($phone, 'c5', 'audio', 'audio/ogg', 'note.ogg', base64_encode('OggSvoice'));
        $this->send($phone, 'c6', 'تم');

        $row = Complaint::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('تأخير التسليم', $row->title);
        $this->assertSame('تأخر تسليم العمل أسبوع', $row->description);
        $this->assertNotNull($row->image_path);
        $this->assertSame('audio/ogg', $row->audio_mime);
        Storage::disk('local')->assertExists((string) $row->image_path);
        Storage::disk('local')->assertExists((string) $row->audio_path);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $this->getJson('/api/admin/complaints')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'تأخير التسليم')
            ->assertJsonPath('data.0.has_image', true)
            ->assertJsonPath('data.0.has_audio', true);
        $this->get('/api/admin/complaints/'.$row->id.'/image')->assertOk();
        $this->patchJson('/api/admin/complaints/'.$row->id, ['status' => 'reviewed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reviewed');

        $role = Role::query()->create(['name' => 'بدون شكاوى', 'abilities' => []]);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));
        $this->getJson('/api/admin/complaints')->assertForbidden();
    }

    public function test_a_complaint_refuses_video(): void
    {
        $phone = '963944000098';
        $this->send($phone, 'v1', 'شكوى');
        $this->send($phone, 'v2', 'عنوان المشكلة');
        $this->send($phone, 'v3', 'وصف المشكلة بالتفصيل');
        $this->sendMedia($phone, 'v4', 'document', 'video/mp4', 'clip.mp4', base64_encode('not-a-video'));

        $this->assertSame(0, Complaint::query()->count());
        Http::assertSent(fn (Request $request): bool => str_contains((string) data_get($request->data(), 'text'), 'فيديو'));
    }

    private function send(string $phone, string $id, string $text): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => $phone,
                'message_id' => $id,
                'text' => $text,
            ])
            ->assertOk();
    }

    private function sendMedia(string $phone, string $id, string $kind, string $mime, string $name, string $data): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => $phone,
                'message_id' => $id,
                'media' => [
                    'kind' => $kind,
                    'mime' => $mime,
                    'filename' => $name,
                    'data_base64' => $data,
                ],
            ])
            ->assertOk();
    }
}
