<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الشحنات ومتابعاتها، محاولات الاتصال، المشكلات وتحديثاتها، المؤشرات اليومية للمتاجر.
 *
 * - كود الشحنة فريد: المتابعة الجديدة لكود موجود تُضاف إلى shipment_followups ولا تنشئ شحنة جديدة.
 * - unique(daily_report_id, shipment_id) يمنع تكرار الكود داخل التقرير الواحد.
 * - حالة العميل، حالة المندوب، نتيجة المتابعة، وحالة المعالجة حقول منفصلة.
 */
return new class extends Migration
{
    public const RESULTS = [
        'delivered_after_followup',       // تم التسليم بعد المتابعة
        'courier_no_answer',              // المندوب لم يرد
        'courier_answered_still_delayed', // المندوب رد والطلبية ما زالت متأخرة
        'returned_or_not_with_courier',   // الطلبية رجعت أو لم تعد مع المندوب
        'customer_no_answer',             // المندوب رد لكن العميل لم يرد
        'courier_promised_delivery',      // المندوب وعد بالتسليم
        'other_issue',                    // مشكلة أخرى
    ];

    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->date('registered_at');                                 // تاريخ تسجيلها لأول مرة
            $table->foreignId('first_report_id')->nullable()->constrained('daily_reports')->nullOnDelete();
            // قيم مُلخّصة من آخر متابعة معتمدة (تُحدّث تلقائياً)
            $table->foreignId('last_followup_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('last_result', self::RESULTS)->nullable()->index();
            $table->enum('customer_status', ['answered', 'no_answer', 'unreachable', 'refused', 'unknown'])->nullable();
            $table->enum('courier_status', ['answered', 'no_answer', 'unreachable', 'unknown'])->nullable();
            $table->enum('processing_status', ['open', 'in_progress', 'resolved', 'closed'])->default('open')->index();
            $table->unsignedInteger('followups_count')->default(0);
            $table->timestamp('last_followup_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'registered_at']);
        });

        Schema::create('shipment_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('daily_report_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('report_store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('import_row_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->dateTime('followup_at');
            $table->enum('result', self::RESULTS);
            $table->enum('customer_status', ['answered', 'no_answer', 'unreachable', 'refused', 'unknown'])->nullable();
            $table->enum('courier_status', ['answered', 'no_answer', 'unreachable', 'unknown'])->nullable();
            $table->boolean('is_official')->default(false)->index();       // true فقط بعد اعتماد التقرير
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['daily_report_id', 'shipment_id']);
            $table->index(['shipment_id', 'followup_at']);
            $table->index(['user_id', 'followup_at']);
            $table->index(['result', 'followup_at']);
        });

        Schema::create('contact_attempts', function (Blueprint $table) {
            $table->id();
            $table->enum('party_type', ['store', 'customer', 'courier']);
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shipment_code', 80)->nullable();
            $table->foreignId('daily_report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->dateTime('contacted_at');
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->enum('result', ['answered', 'no_answer', 'busy', 'unreachable', 'wrong_number', 'callback_requested', 'other']);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('contacted_at');
            $table->index(['user_id', 'contacted_at']);
            $table->index(['store_id', 'contacted_at']);
            $table->index(['party_type', 'result']);
        });

        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->string('issue_number', 30)->unique();                  // ISS-2026-000001
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('daily_report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('reported_at');
            $table->enum('type', [
                'shipments_decline', 'shipments_stopped', 'delivery_delay', 'high_returns',
                'shipment_data_error', 'communication', 'pickup', 'store_inquiry', 'other',
            ])->index();
            $table->string('title', 200);
            $table->text('description');
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['new', 'in_progress', 'resolved', 'closed'])->default('new')->index();
            $table->text('action_taken')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'status']);
        });

        Schema::create('issue_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20)->nullable();
            $table->foreignId('old_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('new_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        // ملخص يومي لكل متجر يُبنى من التقارير المعتمدة فقط (Rebuildable)
        Schema::create('store_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('metric_date');
            $table->unsignedInteger('shipments_count')->nullable();
            $table->unsignedInteger('reports_count')->default(0);
            $table->unsignedInteger('followups_count')->default(0);
            $table->unsignedInteger('delivered_after_followup')->default(0);
            $table->unsignedInteger('customer_no_answer')->default(0);
            $table->unsignedInteger('courier_no_answer')->default(0);
            $table->unsignedInteger('contact_attempts')->default(0);
            $table->unsignedInteger('issues_opened')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'metric_date']);
            $table->index('metric_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_daily_metrics');
        Schema::dropIfExists('issue_updates');
        Schema::dropIfExists('issues');
        Schema::dropIfExists('contact_attempts');
        Schema::dropIfExists('shipment_followups');
        Schema::dropIfExists('shipments');
    }
};
