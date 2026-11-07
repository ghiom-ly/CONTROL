<?php

namespace App\Support;

/**
 * نص واتساب: تنسيق واتساب (*عريض* _مائل_) بدل Markdown، وروابط wa.me آمنة الطول.
 * واتساب يرفض الروابط الطويلة («الرابط غير صالح») — الرسالة الطويلة تُنسخ في المتصفح وتُفتح المحادثة بلا نص.
 */
class WhatsAppText
{
    /** أقصى طول للنص بعد الترميز داخل الرابط (حرف عربي ≈ 6 رموز) */
    public const MAX_ENCODED = 3000;

    /** ما يعادله تقريباً بالأحرف العربية — للعرض فقط */
    public const MAX_CHARS = 500;

    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/\*\*(.+?)\*\*/us', '*$1*', $text);          // **عريض** ⇒ *عريض*
        $text = preg_replace('/(?<![\p{L}\p{N}])__(.+?)__(?![\p{L}\p{N}])/us', '_$1_', $text);
        $text = preg_replace('/^[ \t]*#{1,6}[ \t]+/um', '', $text);          // عناوين Markdown

        return trim($text);
    }

    public static function fits(string $text): bool
    {
        return strlen(rawurlencode($text)) <= self::MAX_ENCODED;
    }

    /** رابط wa.me — بلا نص إن كانت الرسالة أطول من حد واتساب */
    public static function url(?string $phone, string $text): string
    {
        $base = 'https://wa.me/'.($phone ?? '');

        return self::fits($text) ? $base.'?text='.rawurlencode($text) : $base;
    }
}
