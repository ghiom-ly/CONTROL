<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 4: أرقام البداية لكل متجر، وقت أول إرسال (الانصراف)، الإجازات، سجل التنبيهات،
 * نوع مطابقة «قائمة متاجر»، وصلاحية إدارة الحضور للمسؤول.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_stores', function (Blueprint $table) {
            $table->unsignedInteger('start_orders')->nullable()->after('store_id');
            $table->decimal('start_rate', 5, 2)->nullable()->after('start_orders');
            $table->string('start_source', 10)->nullable()->after('start_rate');
        });

        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dateTime('first_submitted_at')->nullable()->after('submitted_at');
        });
        DB::table('daily_reports')->whereNotNull('submitted_at')->update(['first_submitted_at' => DB::raw('submitted_at')]);

        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->enum('type', ['leave', 'sick', 'mission', 'other'])->default('leave');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'from_date']);
        });

        Schema::create('attendance_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('alert_date');
            $table->string('kind', 30);
            $table->timestamps();
            $table->unique(['user_id', 'alert_date', 'kind']);
        });

        DB::statement("ALTER TABLE import_mappings MODIFY layout ENUM('orders','stores','store_list') NOT NULL");
        DB::statement("ALTER TABLE system_settings MODIFY type ENUM('string','integer','decimal','boolean','json','time','date') NOT NULL DEFAULT 'string'");

        $this->ensureSettings();

        $perm = DB::table('permissions')->where('name', 'attendance.manage')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => 'attendance.manage', 'display_name' => 'إدارة الحضور والإجازات',
                'group' => 'الموظفون', 'created_at' => now(), 'updated_at' => now()]);
        $admin = DB::table('roles')->where('name', 'admin')->value('id');
        if ($admin) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $perm]);
        }
    }

    /**
     * إعدادات الحضور تُضاف هنا (لا تعتمد على البذر) حتى تعمل على نسخة مثبّتة تُحدَّث بـ migrate فقط.
     * بداية احتساب الحضور = يوم التحديث.
     */
    public function ensureSettings(): void
    {
        $now = now();
        foreach ([
            ['attendance_late_after', '10:00', 'time', 'بعد هذه الساعة يُعتبر الموظف متأخراً', 'وقت بدء الشفت المرجعي للتأخير'],
            ['attendance_grace_minutes', '0', 'integer', 'دقائق السماح قبل احتساب التأخير', null],
            ['attendance_start_date', $now->toDateString(), 'date', 'بداية احتساب الحضور والغياب', 'لا يُحسب غياب قبل هذا التاريخ'],
        ] as [$key, $value, $type, $label, $desc]) {
            DB::table('system_settings')->insertOrIgnore([
                'key' => $key, 'value' => $value, 'type' => $type, 'group' => 'attendance', 'label' => $label,
                'description' => $desc, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        \Illuminate\Support\Facades\Cache::forget('system_settings.all');
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'attendance.manage')->delete();
        DB::table('system_settings')->where('type', 'date')->update(['type' => 'string']);
        DB::statement("ALTER TABLE system_settings MODIFY type ENUM('string','integer','decimal','boolean','json','time') NOT NULL DEFAULT 'string'");
        DB::table('import_mappings')->where('layout', 'store_list')->delete();
        DB::statement("ALTER TABLE import_mappings MODIFY layout ENUM('orders','stores') NOT NULL");
        Schema::dropIfExists('attendance_alerts');
        Schema::dropIfExists('leaves');
        Schema::table('daily_reports', fn (Blueprint $t) => $t->dropColumn('first_submitted_at'));
        Schema::table('report_stores', fn (Blueprint $t) => $t->dropColumn(['start_orders', 'start_rate', 'start_source']));
    }
};
