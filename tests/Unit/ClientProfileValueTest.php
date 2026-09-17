<?php

namespace Tests\Unit;

use App\Support\ClientProfileValue;
use Tests\TestCase;

class ClientProfileValueTest extends TestCase
{
    public function test_keyboard_labels_are_rejected(): void
    {
        $this->assertTrue(ClientProfileValue::isKeyboardLabel('🆕 طلب جديد'));
        $this->assertTrue(ClientProfileValue::isKeyboardLabel('طلباتي'));
        $this->assertFalse(ClientProfileValue::isKeyboardLabel('Ahmad Ali'));
    }

    public function test_phone_like_values_are_not_usable_names(): void
    {
        $this->assertNull(ClientProfileValue::usableName('0957470371'));
        $this->assertSame('Ahmad Ali', ClientProfileValue::usableName('Ahmad Ali'));
        $this->assertSame('0957470371', ClientProfileValue::usablePhone('0957470371'));
        $this->assertNull(ClientProfileValue::usablePhone('🆕 طلب جديد'));
    }
}
