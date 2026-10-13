<?php

namespace Tests\Unit;

use App\Support\PaymentTermLabel;
use PHPUnit\Framework\TestCase;

class PaymentTermLabelTest extends TestCase
{
    public function test_odoo_default_terms_read_in_arabic(): void
    {
        $this->assertSame('الدفع الفوري', PaymentTermLabel::arabic('Immediate Payment'));
        $this->assertSame('خلال 15 يوماً', PaymentTermLabel::arabic('15 Days'));
        $this->assertSame('خلال 7 أيام', PaymentTermLabel::arabic('7 days'));
        $this->assertSame('نهاية الشهر التالي', PaymentTermLabel::arabic('End of Following Month'));
        $this->assertSame('30% الآن، والباقي خلال 60 يوماً', PaymentTermLabel::arabic('30% Now, Balance 60 Days'));
        $this->assertSame('خصم 2% عند الدفع خلال 7 أيام، والصافي خلال 30 يوماً', PaymentTermLabel::arabic('2/7 Net 30'));
    }

    public function test_an_arabic_name_from_odoo_is_kept(): void
    {
        $this->assertSame('الدفع خلال 45 يوم', PaymentTermLabel::arabic('الدفع خلال 45 يوم'));
    }
}
