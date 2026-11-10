<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الرواتب: راتب أساسي بتاريخ سريان + كشف شهري مربوط بالحضور (غياب، شفت غير مكتمل) + إضافات/خصومات يدوية */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 3)->nullable();
            $table->date('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'effective_from']);
        });

        Schema::create('payroll_months', function (Blueprint $table) {
            $table->id();
            $table->date('month')->unique();
            $table->enum('status', ['draft', 'approved'])->default('draft');
            $table->timestamp('computed_at')->nullable();
            $table->foreignId('computed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_month_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('base_salary', 12, 3);
            $table->decimal('day_value', 12, 3);
            $table->unsignedTinyInteger('paid_days');
            $table->decimal('base_due', 12, 3);
            $table->unsignedSmallInteger('absent_days')->default(0);
            $table->json('absent_dates')->nullable();
            $table->decimal('absence_deduction', 12, 3)->default(0);
            $table->unsignedSmallInteger('incomplete_days')->default(0);
            $table->json('incomplete_dates')->nullable();
            $table->unsignedTinyInteger('incomplete_percent')->default(50);
            $table->decimal('incomplete_deduction', 12, 3)->default(0);
            $table->unsignedSmallInteger('late_count')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('reports_approved')->default(0);
            $table->unsignedSmallInteger('reports_total')->default(0);
            $table->decimal('additions', 12, 3)->default(0);
            $table->decimal('deductions', 12, 3)->default(0);
            $table->decimal('net', 12, 3);
            $table->timestamps();
            $table->unique(['payroll_month_id', 'user_id']);
        });

        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_month_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['addition', 'deduction']);
            $table->string('label', 150);
            $table->decimal('amount', 12, 3);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('system_settings')->insertOrIgnore([
            'key' => 'payroll_incomplete_percent', 'value' => '50', 'type' => 'integer', 'group' => 'payroll',
            'label' => 'خصم الشفت غير المكتمل (% من قيمة اليوم)', 'description' => 'يوم بدأ فيه الموظف الشفت ولم يرسل تقريره',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Cache::forget('system_settings.all');
        // الجمعة صارت عطلة أسبوعية في الحضور ⇒ إحصائيات الغياب المخزّنة قديمة
        \App\Services\Analytics\AnalyticsService::flush();
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_months');
        Schema::dropIfExists('employee_salaries');
        DB::table('system_settings')->where('key', 'payroll_incomplete_percent')->delete();
        Cache::forget('system_settings.all');
    }
};
