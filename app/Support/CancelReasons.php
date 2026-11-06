<?php

namespace App\Support;

use App\Models\SystemSetting;

/** أسباب إلغاء الطلبيات من الإعدادات (سطر لكل سبب) — «أخرى» تُضاف دائماً في الواجهة */
class CancelReasons
{
    public const OTHER = 'أخرى';

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) SystemSetting::get('confirmation_cancel_reasons', ''))), fn ($r) => $r !== '' && $r !== self::OTHER));
    }
}
