<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\ServiceRequest;
use App\Support\ClientReplyGuard;
use App\Support\ClientUploadGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClientBotGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reply_stays_inside_this_client(): void
    {
        $client = Client::factory()->create([
            'phone' => '+963944000001',
            'email' => 'nour@hoc.agency',
        ]);
        ServiceRequest::factory()->for($client)->create(['number' => 'REQ-2026-000011']);
        $guard = new ClientReplyGuard;

        $this->assertTrue($guard->allows($client, 'طلبك #REQ-2026-000011 ورقمك 0944000001'));
        $this->assertTrue($guard->allows($client, 'احكي معنا على 0947823488'));
        $this->assertFalse($guard->allows($client, 'رقم غيرك 0991112233'));
        $this->assertFalse($guard->allows($client, 'اكتب لـ other@gmail.com'));
        $this->assertFalse($guard->allows($client, 'طلب غيرك #REQ-2026-000099'));
        $this->assertFalse($guard->allows($client, 'APP_KEY=base64:secret'));
    }

    public function test_a_receipt_must_match_its_bytes(): void
    {
        $guard = new ClientUploadGuard;

        $this->assertSame('image/jpeg', $guard->assertReceipt("\xFF\xD8\xFF\xD9", 'image/jpeg'));
        $this->assertSame('application/pdf', $guard->assertReceipt('%PDF-1.4', 'application/pdf'));

        $this->expectException(ValidationException::class);
        $guard->assertReceipt('<?php echo 1;', 'image/jpeg');
    }
}
