<?php

namespace Tests\Feature;

use App\Models\PhotographyBooking;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PhotographyAlarmTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_signed_reminder_page_offers_a_calendar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'Asia/Damascus'));
        $booking = $this->confirmedBooking();
        $url = URL::temporarySignedRoute('photography.alarm', now()->addDay(), ['booking' => $booking->id]);

        $this->get($url)
            ->assertOk()
            ->assertSee('موعد التصوير')
            ->assertSee('أضف إلى تقويم غوغل')
            ->assertSee('calendar.google.com', false)
            ->assertSee('event.ics', false);
    }

    public function test_an_unsigned_reminder_link_is_rejected(): void
    {
        $booking = $this->confirmedBooking();

        $this->get('/photography-alarm/'.$booking->id)->assertForbidden();
    }

    private function confirmedBooking(): PhotographyBooking
    {
        $request = ServiceRequest::factory()->create();

        return PhotographyBooking::query()->create([
            'request_id' => $request->id,
            'starts_at' => '2026-10-20 13:00:00',
            'ends_at' => '2026-10-20 16:00:00',
            'status' => PhotographyBooking::CONFIRMED,
        ]);
    }
}
