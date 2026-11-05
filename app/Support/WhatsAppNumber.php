<?php

namespace App\Support;

/**
 * رقم واتساب بصيغة دولية بلا رموز (E.164 بدون +)، لأي دولة.
 * الرقم المحلي (يبدأ بصفر أو 9 أرقام تبدأ بـ 9) يُعتبر ليبياً.
 */
class WhatsAppNumber
{
    public static function normalize(?string $raw): ?string
    {
        $s = trim((string) ArabicNormalizer::digits((string) $raw));
        $s = preg_replace('/\(\s*0\s*\)/', '', $s);          // +966 (0) 50… — الصفر المحلي بين قوسين
        $international = str_starts_with($s, '+') || str_starts_with(preg_replace('/\D+/', '', $s), '00');
        $d = preg_replace('/\D+/', '', $s);

        if ($international) {
            $d = str_starts_with($d, '00') ? substr($d, 2) : $d;
            if (str_starts_with($d, '2180')) {                 // +218 0 91…
                $d = '218'.substr($d, 4);
            }
        } elseif (str_starts_with($d, '0')) {                 // 091… محلي ليبي
            $d = '218'.substr($d, 1);
        } elseif (strlen($d) === 9 && $d[0] === '9') {         // 91… بلا صفر
            $d = '218'.$d;
        }

        if (str_starts_with($d, '218')) {
            return preg_match('/^218\d{9}$/', $d) ? $d : null;
        }

        // أي دولة: مفتاح لا يبدأ بصفر، والطول الدولي 8–15 رقماً
        return preg_match('/^[1-9]\d{7,14}$/', $d) ? $d : null;
    }
}
