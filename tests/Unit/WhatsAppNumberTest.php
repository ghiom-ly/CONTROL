<?php

namespace Tests\Unit;

use App\Support\WhatsAppNumber;
use PHPUnit\Framework\TestCase;

class WhatsAppNumberTest extends TestCase
{
    public function test_whatsapp_number_normalization(): void
    {
        foreach (['٠٩١-٢٣٤ ٥٦٧٨', '+218 91 2345678', '00218912345678', '0912345678', '218912345678'] as $raw) {
            $this->assertSame('218912345678', WhatsAppNumber::normalize($raw), $raw);
        }
        foreach (['12345', '', null, '0212345678901', 'abc'] as $bad) {
            $this->assertNull(WhatsAppNumber::normalize($bad), (string) $bad);
        }
    }
}
