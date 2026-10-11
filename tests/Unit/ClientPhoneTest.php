<?php

namespace Tests\Unit;

use App\Support\ClientPhone;
use Tests\TestCase;

class ClientPhoneTest extends TestCase
{
    public function test_a_number_with_a_country_code_keeps_the_plus(): void
    {
        $this->assertSame('+963944123456', ClientPhone::interpret('+963 944 123 456')['phone']);
        $this->assertSame('+963944123456', ClientPhone::interpret('00963944123456')['phone']);
        $this->assertSame('+963944123456', ClientPhone::interpret('963944123456')['phone']);
        $this->assertSame('+447700900123', ClientPhone::interpret('+44 7700 900123')['phone']);
    }

    public function test_a_local_number_waits_for_the_country(): void
    {
        $read = ClientPhone::interpret('0944123456');

        $this->assertSame('country', $read['status']);
        $this->assertSame('944123456', $read['national']);
        $this->assertSame('+963944123456', ClientPhone::applyCountry('944123456', 'سوريا'));
        $this->assertSame('+971501234567', ClientPhone::applyCountry('0501234567', 'الإمارات'));
        $this->assertSame('+963944123456', ClientPhone::applyCountry('944123456', '963'));
    }

    public function test_take_my_current_number_is_the_whatsapp_caller(): void
    {
        $this->assertSame('current', ClientPhone::interpret('خذ رقمي الحالي')['status']);
        $this->assertSame('current', ClientPhone::interpret('رقم الواتساب')['status']);
        $this->assertSame('+963911111111', ClientPhone::fromCallerDigits('963911111111'));
    }

    public function test_arabic_digits_and_unknown_text_are_handled(): void
    {
        $this->assertSame('+963944123456', ClientPhone::interpret('٩٦٣٩٤٤١٢٣٤٥٦')['phone']);
        $this->assertSame('invalid', ClientPhone::interpret('مرحبا')['status']);
        $this->assertNull(ClientPhone::applyCountry('944123456', 'كوكب'));
    }
}
