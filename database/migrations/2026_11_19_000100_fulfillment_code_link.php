<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** أكواد منظومة التجهيز (SX…): الضغط على الكود ينسخه ويفتح المنظومة الثانية (بحثها لا يقبل رابطاً مباشراً) */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('system_settings')->insertOrIgnore([
            [
                'key' => 'fulfillment_code_prefix', 'value' => 'SX', 'type' => 'string', 'group' => 'reports',
                'label' => 'بداية أكواد منظومة التجهيز', 'description' => 'الأكواد اللي تبدأ بهذه الحروف: الضغط عليها ينسخ الكود ويفتح منظومة التجهيز — اتركه فارغاً للإلغاء',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'fulfillment_url', 'value' => 'https://fulfillment.nawris.com/', 'type' => 'string', 'group' => 'reports',
                'label' => 'رابط منظومة التجهيز', 'description' => 'الصفحة اللي تنفتح بعد نسخ الكود (صفحة البحث عن الطلبيات)',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
        Cache::forget('system_settings.all');
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', ['fulfillment_code_prefix', 'fulfillment_url'])->delete();
        Cache::forget('system_settings.all');
    }
};
