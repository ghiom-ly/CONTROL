<?php

namespace App\Support;

use App\Models\Shipment;

/**
 * تقسيم الأكواد الملصوقة (كما تُنسخ من واتساب) إلى قائمة موحّدة بلا تكرار.
 */
class CodeParser
{
    public const MAX_LENGTH = 80;

    /** @return list<string> */
    public static function split(?string $raw): array
    {
        $parts = preg_split('/[\s,،;؛]+/u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $part) {
            $code = Shipment::normalizeCode($part);
            // كود الشحنة فيه رقم دايماً — «لا يوجد» و«ما فيش» و«-» كلام مش أكواد
            if ($code === null || ! preg_match('/\d/', $code)) {
                continue;
            }
            if (mb_strlen($code) > self::MAX_LENGTH) {
                throw new \InvalidArgumentException("الكود أطول من ".self::MAX_LENGTH.' حرفاً: '.mb_substr($code, 0, 20).'…');
            }
            $out[$code] = true;
        }

        // مفاتيح PHP الرقمية تتحول إلى int — نعيدها نصوصاً
        return array_map('strval', array_keys($out));
    }
}
