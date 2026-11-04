<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 2: بيانات الشفت في التقرير، أكواد المتابعة كنص حتى الاعتماد، ومفتاح مصدر المشكلة.
 *
 * - أكواد المسودة لا تُنشئ صفوفاً في shipments؛ تُحفظ في shipment_followups بـ shipment_code + store_id
 *   و shipment_id فارغ، وتُربط بالشحنة عند الاعتماد فقط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dateTime('shift_started_at')->nullable()->after('work_date');
            $table->unsignedInteger('start_total_orders')->nullable()->after('shift_started_at');
            $table->decimal('start_delivery_rate', 5, 2)->nullable()->after('start_total_orders');
            $table->decimal('end_delivery_rate', 5, 2)->nullable()->after('start_delivery_rate');
            $table->json('attempt_times')->nullable()->after('contact_attempts_count');
            $table->text('attempts_over_limit_reason')->nullable()->after('attempt_times');
            $table->json('issue_requests')->nullable()->after('inquiries');
        });

        Schema::table('report_stores', function (Blueprint $table) {
            $table->boolean('is_outside_assignment')->default(false)->after('store_id');
        });

        Schema::table('shipment_followups', function (Blueprint $table) {
            $table->string('shipment_code', 80)->nullable()->after('shipment_id');
            $table->foreignId('store_id')->nullable()->after('shipment_code')->constrained()->restrictOnDelete();
        });

        // تعبئة الصفوف القديمة من جدول الشحنات
        DB::table('shipment_followups')->whereNull('shipment_code')->orderBy('id')->each(function ($f) {
            $s = DB::table('shipments')->where('id', $f->shipment_id)->first(['code', 'store_id']);
            if ($s) {
                DB::table('shipment_followups')->where('id', $f->id)->update(['shipment_code' => $s->code, 'store_id' => $s->store_id]);
            }
        });

        // الفهرس الفريد الجديد أولاً (يخدم مفتاح daily_report_id) ثم حذف القديم
        Schema::table('shipment_followups', function (Blueprint $table) {
            $table->unique(['daily_report_id', 'shipment_code']);
            $table->index('shipment_code');
            $table->dropForeign(['shipment_id']);
        });

        Schema::table('shipment_followups', function (Blueprint $table) {
            $table->dropUnique(['daily_report_id', 'shipment_id']);
            $table->unsignedBigInteger('shipment_id')->nullable()->change();
            $table->string('shipment_code', 80)->nullable(false)->change();
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->string('source_key', 40)->nullable()->after('daily_report_id');
            $table->unique(['daily_report_id', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->index('daily_report_id');   // يخدم المفتاح الأجنبي بعد حذف الفهرس المركّب
            $table->dropUnique(['daily_report_id', 'source_key']);
            $table->dropColumn('source_key');
        });

        DB::table('shipment_followups')->whereNull('shipment_id')->delete();

        Schema::table('shipment_followups', function (Blueprint $table) {
            $table->dropForeign(['shipment_id']);
            $table->dropForeign(['store_id']);
            $table->unsignedBigInteger('shipment_id')->nullable(false)->change();
            $table->unique(['daily_report_id', 'shipment_id']);
        });

        Schema::table('shipment_followups', function (Blueprint $table) {
            $table->dropUnique(['daily_report_id', 'shipment_code']);
            $table->dropIndex(['shipment_code']);
            $table->dropColumn(['shipment_code', 'store_id']);
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
        });

        Schema::table('report_stores', fn (Blueprint $table) => $table->dropColumn('is_outside_assignment'));

        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dropColumn([
                'shift_started_at', 'start_total_orders', 'start_delivery_rate', 'end_delivery_rate',
                'attempt_times', 'attempts_over_limit_reason', 'issue_requests',
            ]);
        });
    }
};
