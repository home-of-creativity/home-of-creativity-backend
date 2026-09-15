<?php

namespace Tests\Feature;

use App\Models\LandingReel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LandingReelTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_reels_are_readable(): void
    {
        LandingReel::query()->create([
            'title_en' => 'Published reel',
            'title_ar' => 'ريل منشور',
            'video_path' => 'reels/published.mp4',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        LandingReel::query()->create([
            'title_en' => 'Draft reel',
            'title_ar' => 'ريل مخفي',
            'video_path' => 'reels/draft.mp4',
            'sort_order' => 2,
            'is_published' => false,
        ]);

        $this->getJson('/api/reels')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title_en', 'Published reel');

        $this->assertStringContainsString('/storage/reels/published.mp4', (string) $this->getJson('/api/reels')->json('data.0.video_url'));
    }

    public function test_guest_cannot_create_reel(): void
    {
        $this->postJson('/api/admin/reels', [
            'title_en' => 'Reel',
            'title_ar' => 'ريل',
        ])->assertUnauthorized();
    }

    public function test_admin_can_create_and_update_reel(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->post('/api/admin/reels', [
            'title_en' => 'Studio reel',
            'title_ar' => 'ريل الاستوديو',
            'is_published' => true,
            'video' => UploadedFile::fake()->create('studio.mp4', 800, 'video/mp4'),
        ])->assertCreated()
            ->assertJsonPath('data.title_en', 'Studio reel');

        $reel = LandingReel::query()->first();
        $this->assertNotNull($reel);
        Storage::disk('public')->assertExists($reel->video_path);

        $this->post("/api/admin/reels/{$reel->id}", [
            '_method' => 'PUT',
            'title_en' => 'Studio reel updated',
            'title_ar' => 'ريل الاستوديو',
            'is_published' => '1',
        ])->assertOk()
            ->assertJsonPath('data.title_en', 'Studio reel updated');

        $this->post("/api/admin/reels/{$reel->id}", [
            'title_en' => 'Studio reel recut',
            'title_ar' => 'ريل الاستوديو',
            'is_published' => '1',
            'video' => UploadedFile::fake()->create('recut.mp4', 800, 'video/mp4'),
        ])->assertOk()
            ->assertJsonPath('data.title_en', 'Studio reel recut');
    }

    public function test_store_requires_video(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/reels', [
            'title_en' => 'Missing clip',
            'title_ar' => 'بدون فيديو',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['video']);
    }
}
