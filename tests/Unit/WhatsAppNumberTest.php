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
        foreach (['12345', '', null, '0212345678901', 'abc', '+123', '+1234567890123456'] as $bad) {
            $this->assertNull(WhatsAppNumber::normalize($bad), (string) $bad);
        }
    }

    public function test_international_numbers_from_any_country(): void
    {
        $cases = [
            '+201148052404' => '201148052404',        // مصر
            '00213 778 876 607' => '213778876607',    // الجزائر
            '+216 55 767 672' => '21655767672',       // تونس
            '+966 (0) 50-123-4567' => '966501234567', // السعودية مع صفر بين قوسين
            '+1 (555) 123.4567' => '15551234567',     // أمريكا
            '+44 7911 123456' => '447911123456',      // بريطانيا
            '+٩٧١٥٠١٢٣٤٥٦٧' => '971501234567',       // أرقام عربية
            '+218 (0)91 2345678' => '218912345678',   // ليبيا مع صفر
            '201148052404' => '201148052404',         // مفتاح دولة بلا +
        ];
        foreach ($cases as $raw => $expected) {
            $this->assertSame($expected, WhatsAppNumber::normalize($raw), $raw);
        }
    }
}
