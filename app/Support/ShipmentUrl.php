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
        if ($template === '' || $code === '' || ! str_contains($template, '{code}') || self::fulfillment($code) !== null) {
            return null;
        }

        return str_replace('{code}', rawurlencode($code), $template);
    }

    /**
     * أكواد منظومة التجهيز (تبدأ بالبادئة، مثل SX…): ترجع رابط المنظومة لفتحه بعد نسخ الكود.
     * بحث تلك المنظومة لا يقبل الكود في الرابط، لذلك لا يُضاف الكود للرابط.
     */
    public static function fulfillment(?string $code): ?string
    {
        $prefix = trim((string) SystemSetting::get('fulfillment_code_prefix'));
        $url = trim((string) SystemSetting::get('fulfillment_url'));
        $code = trim((string) $code);
        if ($prefix === '' || $url === '' || $code === '' || stripos($code, $prefix) !== 0) {
            return null;
        }

        return $url;
    }
}
