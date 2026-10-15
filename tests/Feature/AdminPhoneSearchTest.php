<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\ClientPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPhoneSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_search_reads_as_phone_digits_in_any_form(): void
    {
        $this->assertSame('944000111', ClientPhone::searchDigits('0944000111'));
        $this->assertSame('944000111', ClientPhone::searchDigits('+963 944 000 111'));
        $this->assertSame('944000111', ClientPhone::searchDigits('00963944000111'));
        $this->assertSame('944000111', ClientPhone::searchDigits('٠٩٤٤٠٠٠١١١'));
        $this->assertSame('944000', ClientPhone::searchDigits('0944-000'));
        $this->assertNull(ClientPhone::searchDigits('نور'));
        $this->assertNull(ClientPhone::searchDigits('REQ-2026'));
        $this->assertNull(ClientPhone::searchDigits('09'));
    }

    public function test_clients_requests_and_reports_are_found_by_mobile_number(): void
    {
        Sanctum::actingAs($this->admin());
        $noor = Client::factory()->create(['name' => 'نور', 'phone' => '+963 944-000 111', 'company_name' => 'النور']);
        $other = Client::factory()->create(['name' => 'سامر', 'phone' => '0933123456', 'company_name' => 'سامر']);
        $request = ServiceRequest::factory()->create(['client_id' => $noor->id]);
        ServiceRequest::factory()->create(['client_id' => $other->id]);

        foreach (['0944000111', '+963944000111', '٠٩٤٤ ٠٠٠ ١١١', '944000'] as $search) {
            $clients = $this->getJson('/api/admin/clients?search='.urlencode($search))->assertOk()->json('data');
            $this->assertSame([$noor->id], array_column($clients, 'id'), $search);

            $requests = $this->getJson('/api/admin/requests?search='.urlencode($search))->assertOk()->json('data');
            $this->assertSame([$request->id], array_column($requests, 'id'), $search);

            $reports = $this->getJson('/api/admin/reports?search='.urlencode($search))->assertOk()->json('data');
            $this->assertSame([$noor->id], array_column($reports, 'id'), $search);
        }
    }

    public function test_a_whatsapp_client_is_found_by_its_chat_number(): void
    {
        Sanctum::actingAs($this->admin());
        $client = Client::factory()->create([
            'name' => 'ريم',
            'phone' => '0955777888',
            'company_name' => 'ريم',
            'telegram_user_id' => Client::WHATSAPP_PREFIX.'963955777888',
        ]);

        $found = $this->getJson('/api/admin/clients?search='.urlencode('+963955777888'))->assertOk()->json('data');

        $this->assertSame([$client->id], array_column($found, 'id'));
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }
}
