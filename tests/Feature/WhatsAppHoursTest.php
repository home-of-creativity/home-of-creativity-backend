<?php

namespace Tests\Feature;

use App\Models\OpsSetting;
use App\Support\WorkCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WhatsAppHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_full_day_window_stays_open_on_friday_evening(): void
    {
        OpsSetting::setValue(WorkCalendar::WHATSAPP_OPEN_KEY, '00:00');
        OpsSetting::setValue(WorkCalendar::WHATSAPP_CLOSE_KEY, '23:59');

        $fridayEvening = Carbon::parse('2026-10-16 17:55:00', 'Asia/Damascus');

        $this->assertTrue(app(WorkCalendar::class)->isWhatsAppOpen($fridayEvening));
    }

    public function test_a_partial_window_stays_closed_outside_its_hours(): void
    {
        OpsSetting::setValue(WorkCalendar::WHATSAPP_OPEN_KEY, '00:00');
        OpsSetting::setValue(WorkCalendar::WHATSAPP_CLOSE_KEY, '11:59');

        $fridayEvening = Carbon::parse('2026-10-16 17:55:00', 'Asia/Damascus');

        $this->assertFalse(app(WorkCalendar::class)->isWhatsAppOpen($fridayEvening));
    }
}
