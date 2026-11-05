<?php

namespace App\Support;

/** رقم واتساب ليبي بصيغة دولية بلا رموز: 2189xxxxxxxx */
class WhatsAppNumber
{
    public static function normalize(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) ArabicNormalizer::digits((string) $raw));
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (str_starts_with($d, '0')) {
            $d = '218'.substr($d, 1);
        }

        return preg_match('/^218\d{9}$/', $d) ? $d : null;
    }
}
