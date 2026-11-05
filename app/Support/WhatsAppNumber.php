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
        if (str_starts_with($d, '2180')) {           // +218 (0)91…
            $d = '218'.substr($d, 4);
        } elseif (str_starts_with($d, '0')) {        // 091…
            $d = '218'.substr($d, 1);
        } elseif (strlen($d) === 9 && $d[0] === '9') { // 91… بلا صفر
            $d = '218'.$d;
        }

        return preg_match('/^218\d{9}$/', $d) ? $d : null;
    }
}
