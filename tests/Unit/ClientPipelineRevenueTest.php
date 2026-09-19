<?php

namespace Tests\Unit;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPipelineRevenueTest extends TestCase
{
    use RefreshDatabase;

    public function test_won_revenue_sums_fully_paid_requests_and_skips_cancelled(): void
    {
        $client = Client::factory()->create();
        ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'status' => RequestStatus::PaymentConfirmed,
            'amount_total' => 1000,
            'quotation_amount' => 1000,
            'amount_paid' => 1000,
            'odoo_won_at' => now(),
        ]);
        ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'status' => RequestStatus::Cancelled,
            'amount_total' => 400,
            'quotation_amount' => 400,
            'amount_paid' => 400,
        ]);
        ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'status' => RequestStatus::QuotationSent,
            'quotation_amount' => 250,
        ]);

        $this->assertEqualsWithDelta(1000.0, $client->pipelineRevenue(wonOnly: true), 0.01);
        $this->assertEqualsWithDelta(1250.0, $client->pipelineRevenue(wonOnly: false), 0.01);
    }
}
