<?php

namespace App\Services\Imports;

use App\Support\ArabicNormalizer;

/** توقيع شكل الملف من عناوين أعمدته (لا يتأثر بالترتيب ولا باختلاف الهمزات) */
class HeaderSignature
{
    /** @param list<string> $headers */
    public static function of(array $headers): string
    {
        $norm = array_map(fn ($h) => ArabicNormalizer::normalize((string) $h), $headers);
        sort($norm);

        return hash('sha256', implode('|', $norm));
    }
}
