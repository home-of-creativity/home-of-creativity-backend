<?php

namespace Tests\Feature;

use App\Models\ContactChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_contact_returns_published_channels(): void
    {
        ContactChannel::factory()->create();
        ContactChannel::factory()->whatsapp()->create(['sort_order' => 2]);
        ContactChannel::factory()->social()->create(['sort_order' => 1]);
        ContactChannel::factory()->location()->create();
        ContactChannel::factory()->unpublished()->create(['value' => 'Hidden']);

        $this->getJson('/api/contact')
            ->assertOk()
            ->assertJsonPath('data.mobile.0.value', '+963 968 862 822')
            ->assertJsonPath('data.whatsapp.0.digits', '963954187154')
            ->assertJsonPath('data.social.0.platform', 'instagram')
            ->assertJsonPath('data.location.0.value_ar', 'دمشق، الحمراء')
            ->assertJsonMissing(['value' => 'Hidden']);
    }

    public function test_guest_cannot_manage_contact_channels(): void
    {
        $this->postJson('/api/admin/contact', [
            'kind' => 'mobile',
            'region' => 'SYR',
            'value' => '+963 111 111 111',
            'digits' => '963111111111',
        ])->assertUnauthorized();
    }

    public function test_admin_can_create_and_update_contact_channels(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/contact', [
            'kind' => 'social',
            'platform' => 'facebook',
            'value' => 'Facebook',
            'url' => 'https://www.facebook.com/homeofcreativity',
        ])->assertCreated()
            ->assertJsonPath('data.platform', 'facebook');

        $channel = ContactChannel::query()->first();
        $this->assertNotNull($channel);

        $this->putJson("/api/admin/contact/{$channel->id}", [
            'url' => 'https://www.facebook.com/hoc',
        ])->assertOk()
            ->assertJsonPath('data.url', 'https://www.facebook.com/hoc');

        $this->getJson('/api/admin/contact')
            ->assertOk()
            ->assertJsonPath('data.0.platform', 'facebook');
    }

    public function test_admin_cannot_create_social_without_url(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/contact', [
            'kind' => 'social',
            'platform' => 'instagram',
            'value' => 'Instagram',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    }
}
