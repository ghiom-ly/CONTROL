<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** تقرير تأكيد الطلبيات: نوع عمل الموظف، نوع التقرير، طلبات التأكيد، أسباب الإلغاء */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('work_type', ['followup', 'confirmation'])->default('followup')->after('role_id');
        });
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->enum('kind', ['followup', 'confirmation'])->default('followup')->after('user_id')->index();
        });
        Schema::create('confirmation_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('code', 80);
            $table->enum('result', ['confirmed', 'cancelled', 'no_answer']);
            $table->string('cancel_reason', 150)->nullable();
            $table->timestamps();
            $table->unique(['daily_report_id', 'code']);
            $table->index('code');
            $table->index(['store_id', 'result']);
        });

        DB::statement("ALTER TABLE system_settings MODIFY type ENUM('string','integer','decimal','boolean','json','time','date','text') NOT NULL DEFAULT 'string'");
        $now = now();
        DB::table('system_settings')->insertOrIgnore([
            'key' => 'confirmation_cancel_reasons', 'value' => "ماطلبتش\nسعر غالي\nالطلب مكرر\nالزبون لا يرد", 'type' => 'text',
            'group' => 'reports', 'label' => 'أسباب إلغاء الطلبيات (سطر لكل سبب)', 'description' => 'تظهر لموظفات التأكيد عند إلغاء طلب — «أخرى» تُضاف دائماً',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Cache::forget('system_settings.all');
    }

    public function down(): void
    {
        Schema::dropIfExists('confirmation_orders');
        Schema::table('daily_reports', fn (Blueprint $t) => $t->dropColumn('kind'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('work_type'));
        DB::table('system_settings')->where('key', 'confirmation_cancel_reasons')->delete();
    }
};
