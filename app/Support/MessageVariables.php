<?php

namespace App\Support;

/** متغيرات قوالب الرسائل: العامة لكل الأنواع + الخاصة بكل نوع */
class MessageVariables
{
    public const COMMON = ['اسم_المتجر', 'صاحب_المتجر', 'كود_المتجر', 'التاريخ', 'اسم_المرسل', 'اسم_الشركة'];

    public const BY_TYPE = [
        'open_codes' => ['الأكواد', 'عدد_الأكواد'],
        'issue' => ['عنوان_المشكلة', 'وصف_المشكلة', 'رقم_المشكلة', 'تاريخ_المشكلة'],
        'performance' => ['الفترة', 'عدد_الطلبيات', 'نسبة_البداية', 'نسبة_النهاية', 'التحسن', 'الأكواد_المفتوحة'],
        'general' => [],
    ];

    /** @return list<string> */
    public static function allowed(string $type): array
    {
        return array_merge(self::COMMON, self::BY_TYPE[$type] ?? []);
    }

    /** @return list<string> متغيرات في النص غير مسموحة لهذا النوع */
    public static function unknownIn(string $body, string $type): array
    {
        preg_match_all('/\{([^{}]+)\}/u', $body, $m);

        return array_values(array_unique(array_diff($m[1], self::allowed($type))));
    }
}
