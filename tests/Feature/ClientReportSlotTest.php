<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientReportSlot;
use App\Models\Role;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientReportSlotTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->client = Client::factory()->create(['name' => 'نور', 'company_name' => 'نور']);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_a_calendar_slot_stores_the_plan_and_the_picture_without_publishing(): void
    {
        $created = $this->post('/api/admin/report-slots', [
            'client_id' => $this->client->id,
            'kind' => 'reel',
            'scheduled_at' => '2026-10-20 18:30:00',
            'caption' => 'ريل الإطلاق',
            'image' => UploadedFile::fake()->image('launch.jpg'),
        ])->assertCreated()->json('data');

        $this->assertSame('reel', $created['kind']);
        $this->assertSame('ريل الإطلاق', $created['caption']);
        $this->assertSame($this->client->id, $created['client_id']);
        $this->assertTrue($created['has_image']);
        $this->assertSame(0, SocialPost::query()->count());

        $slot = ClientReportSlot::query()->firstOrFail();
        $this->assertTrue(Storage::disk('local')->exists($slot->image_path));
        $this->get("/api/admin/report-slots/{$slot->id}/image")->assertOk();

        $this->getJson('/api/admin/report-slots?from=2026-10-01&to=2026-11-01')
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'reel');

        $this->postJson('/api/admin/comments', [
            'subject' => 'report_slot',
            'id' => (string) $slot->id,
            'body' => 'الصورة جاهزة للمراجعة',
        ])->assertCreated();

        $this->post("/api/admin/report-slots/{$slot->id}", [
            'client_id' => $this->client->id,
            'kind' => 'post',
            'scheduled_at' => '2026-10-21 09:00:00',
            'caption' => 'بوست الإطلاق',
        ])->assertOk()->assertJsonPath('data.kind', 'post');

        $this->deleteJson("/api/admin/report-slots/{$slot->id}")->assertOk();
        $this->assertSame(0, ClientReportSlot::query()->count());
        Storage::disk('local')->assertMissing($slot->image_path);
    }

    public function test_a_viewer_cannot_add_a_slot(): void
    {
        $viewer = User::factory()->create([
            'is_admin' => false,
            'role_id' => Role::query()->create([
                'name' => 'مشاهدة',
                'abilities' => ['ops.reports.view'],
            ])->id,
        ]);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/admin/report-slots', [
            'client_id' => $this->client->id,
            'kind' => 'image',
            'scheduled_at' => '2026-10-20 18:30:00',
        ])->assertForbidden();

        $this->getJson('/api/admin/report-slots?from=2026-10-01&to=2026-11-01')->assertOk();
    }
}
