<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientReportSlot;
use App\Models\ReportCalendar;
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
        Storage::fake('public');
        $this->client = Client::factory()->create(['name' => 'نور', 'company_name' => 'نور']);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_a_calendar_slot_stores_the_plan_and_the_picture_without_publishing(): void
    {
        $calendar = ReportCalendar::query()->create(['name' => 'سوشال', 'color' => '#33b679']);
        $created = $this->post('/api/admin/report-slots', [
            'client_id' => $this->client->id,
            'calendar_id' => $calendar->id,
            'kind' => 'reel',
            'color' => '#E67C73',
            'scheduled_at' => '2026-10-20 18:30:00',
            'caption' => 'ريل الإطلاق',
            'images' => [
                UploadedFile::fake()->image('launch.jpg'),
                UploadedFile::fake()->image('cover.png'),
            ],
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertCreated()->json('data');

        $this->assertSame('reel', $created['kind']);
        $this->assertSame('ريل الإطلاق', $created['caption']);
        $this->assertSame($this->client->id, $created['client_id']);
        $this->assertSame($calendar->id, $created['calendar_id']);
        $this->assertSame('#e67c73', $created['color']);
        $this->assertTrue($created['has_image']);
        $this->assertCount(2, $created['images']);
        $this->assertStringContainsString('client-logos', (string) $created['logo_url']);
        $this->assertSame(0, SocialPost::query()->count());

        $slot = ClientReportSlot::query()->with('images')->firstOrFail();
        $this->assertCount(2, $slot->images);
        foreach ($slot->images as $image) {
            $this->assertTrue(Storage::disk('local')->exists($image->path));
            $this->get("/api/admin/report-slots/{$slot->id}/images/{$image->id}")->assertOk();
        }
        Storage::disk('public')->assertExists($this->client->fresh()->logo_path);
        $this->getJson('/api/admin/reports?search='.urlencode('نور'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->client->id);
        $this->assertStringContainsString(
            'client-logos',
            (string) $this->getJson('/api/admin/reports?search='.urlencode('نور'))->json('data.0.logo_url'),
        );

        $this->getJson('/api/admin/report-slots?from=2026-10-01&to=2026-11-01')
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'reel')
            ->assertJsonPath('data.0.calendar_id', $calendar->id);

        $this->postJson('/api/admin/comments', [
            'subject' => 'report_slot',
            'id' => (string) $slot->id,
            'body' => 'الصورة جاهزة للمراجعة',
        ])->assertCreated();

        $paths = $slot->images->pluck('path')->all();
        $this->post("/api/admin/report-slots/{$slot->id}", [
            'client_id' => $this->client->id,
            'calendar_id' => $calendar->id,
            'kind' => 'carousel',
            'color' => '#e67c73',
            'scheduled_at' => '2026-10-21 09:00:00',
            'caption' => 'بوست الإطلاق',
            'remove_image_ids' => [$slot->images[0]->id],
        ])->assertOk()
            ->assertJsonPath('data.kind', 'carousel')
            ->assertJsonCount(1, 'data.images');
        Storage::disk('local')->assertMissing($paths[0]);

        $this->deleteJson("/api/admin/report-slots/{$slot->id}")->assertOk();
        $this->assertSame(0, ClientReportSlot::query()->count());
        Storage::disk('local')->assertMissing($paths[1]);
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
            'color' => '#7986cb',
            'scheduled_at' => '2026-10-20 18:30:00',
        ])->assertForbidden();

        $this->getJson('/api/admin/report-slots?from=2026-10-01&to=2026-11-01')->assertOk();
    }
}
