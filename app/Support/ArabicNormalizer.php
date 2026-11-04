<?php

namespace App\Support;

/**
 * توحيد النصوص العربية لأغراض المطابقة والبحث (أسماء المتاجر في ملفات الاستيراد).
 */
class ArabicNormalizer
{
    public static function normalize(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        $text = trim($text);
        // إزالة التشكيل والتطويل
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return mb_strtolower(trim($text));
    }

    /** تحويل الأرقام العربية/الفارسية إلى إنجليزية */
    public static function digits(?string $text): ?string
    {
        return $text === null ? null : strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
