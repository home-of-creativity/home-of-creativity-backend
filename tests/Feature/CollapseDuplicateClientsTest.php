<?php

namespace Tests\Feature;

use App\Actions\CollapseDuplicateClients;
use App\Models\Client;
use App\Models\ServiceRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollapseDuplicateClientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_clients_with_the_same_phone_collapse_into_the_older_row(): void
    {
        $keeper = Client::factory()->create([
            'name' => 'Prodesign',
            'phone' => '0957470371',
            'email' => 'prodesign@example.com',
            'odoo_lead_id' => '337',
            'odoo_partner_id' => '2153',
        ]);
        $duplicate = Client::factory()->create([
            'name' => 'Prodesign',
            'phone' => '0957 470 371',
            'email' => 'other@example.com',
            'odoo_lead_id' => null,
            'odoo_partner_id' => null,
        ]);
        $request = ServiceRequest::factory()->create(['client_id' => $duplicate->id]);

        $removed = app(CollapseDuplicateClients::class)->handle();

        $this->assertSame(1, $removed);
        $this->assertSoftDeleted('clients', ['id' => $keeper->id]);
        $this->assertNull(Client::withTrashed()->find($keeper->id)?->odoo_lead_id);
        $this->assertSame($duplicate->id, $request->fresh()?->client_id);
        $this->assertSame('337', $duplicate->fresh()?->odoo_lead_id);
        $this->assertSame(1, Client::query()->where('name', 'Prodesign')->count());
    }

    public function test_a_spaced_phone_matches_the_existing_client(): void
    {
        $keeper = Client::factory()->create([
            'name' => 'Prodesign',
            'phone' => '0957470371',
            'email' => 'prodesign@example.com',
        ]);

        $this->assertSame($keeper->id, Client::findByContact(null, '0957 470 371')?->id);
        $this->assertSame($keeper->id, Client::findByContact('prodesign@example.com', null)?->id);
    }

    public function test_the_same_odoo_lead_cannot_be_stored_on_two_clients(): void
    {
        Client::factory()->create([
            'odoo_lead_id' => '337',
            'odoo_partner_id' => '2153',
        ]);

        $this->expectException(QueryException::class);

        Client::factory()->create([
            'odoo_lead_id' => '337',
            'odoo_partner_id' => '2154',
        ]);
    }
}
