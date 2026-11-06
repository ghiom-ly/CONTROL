<?php

namespace App\Support;

use App\Models\SystemSetting;

/** رابط صفحة الشحنة في منظومة الشحن — {code} مكان الكود. قالب فارغ = بلا روابط */
class ShipmentUrl
{
    public static function for(?string $code): ?string
    {
        $template = trim((string) SystemSetting::get('shipment_url_template'));
        $code = trim((string) $code);
        if ($template === '' || $code === '' || ! str_contains($template, '{code}')) {
            return null;
        }

        return str_replace('{code}', rawurlencode($code), $template);
    }
}
