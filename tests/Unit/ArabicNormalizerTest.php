<?php

namespace Tests\Unit;

use App\Support\ArabicNormalizer;
use PHPUnit\Framework\TestCase;

class ArabicNormalizerTest extends TestCase
{
    public function test_normalizes_arabic_variants_for_matching(): void
    {
        $this->assertSame(ArabicNormalizer::normalize('متجر الأمــل'), ArabicNormalizer::normalize('متجر  الامل'));
        $this->assertSame(ArabicNormalizer::normalize('مكتبة إقرأ'), ArabicNormalizer::normalize('مكتبه اقرا'));
        $this->assertSame('store 12', ArabicNormalizer::normalize(' Store-١٢ '));
        $this->assertSame('', ArabicNormalizer::normalize(null));
    }

    public function test_converts_digits(): void
    {
        $this->assertSame('0912345678', ArabicNormalizer::digits('٠٩١٢٣٤٥٦٧٨'));
    }
}
