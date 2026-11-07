<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** تحكم المدير في إلغاء التأخير والغياب: مفتاحان عامّان + إلغاء جماعي لفترة */
return new class extends Migration
{
    private const SETTINGS = [
        ['attendance_track_late', 'احتساب التأخير', 'عند الإيقاف لا يُحسب تأخير على أي موظف ولا تصل تنبيهات «لم يبدأ الشفت»'],
        ['attendance_track_absence', 'احتساب الغياب', 'عند الإيقاف لا يُحسب غياب على أي موظف'],
    ];

    public function up(): void
    {
        Schema::create('attendance_waivers', function (Blueprint $table) {
            $table->id();
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('cancel_late')->default(false);
            $table->boolean('cancel_absence')->default(false);
            $table->string('reason', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['from_date', 'to_date']);
        });

        $now = now();
        foreach (self::SETTINGS as [$key, $label, $desc]) {
            DB::table('system_settings')->insertOrIgnore([
                'key' => $key, 'value' => '1', 'type' => 'boolean', 'group' => 'attendance',
                'label' => $label, 'description' => $desc, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        Cache::forget('system_settings.all');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_waivers');
        DB::table('system_settings')->whereIn('key', array_column(self::SETTINGS, 0))->delete();
        Cache::forget('system_settings.all');
    }
};
