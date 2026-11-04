<?php

namespace App\Enums;

/**
 * أدوات مشتركة للـ Enums: قوائم الاختيار والقيم.
 */
trait HasLabels
{
    /** @return array<string,string> value => label */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
