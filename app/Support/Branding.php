<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Storage;

/** هوية الشركة في رأس التقارير */
class Branding
{
    public const DISK = 'private';

    /** @return array{name: string, phone: ?string, address: ?string, logo_path: ?string} */
    public static function header(): array
    {
        $logo = (string) SystemSetting::get('company_logo');
        $disk = Storage::disk(self::DISK);

        return [
            'name' => (string) SystemSetting::get('company_name', 'شركة النورس'),
            'phone' => SystemSetting::get('company_phone'),
            'address' => SystemSetting::get('company_address'),
            'logo_path' => $logo !== '' && $disk->exists($logo) ? $disk->path($logo) : null,
        ];
    }
}
