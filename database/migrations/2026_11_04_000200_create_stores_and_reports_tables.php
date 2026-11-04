<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المتاجر، الاستيراد، التقارير اليومية ومتاجر التقرير، سجل نسخ التقارير.
 *
 * ملاحظة سلامة البيانات: جميع الأعداد nullable عمداً،
 * فالقيمة NULL تعني "غير مُدخلة / غير واضحة" ولا تُحوَّل إلى صفر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();                         // STR-00001
            $table->string('name', 200)->index();
            $table->string('normalized_name', 200)->index();               // للمطابقة عند الاستيراد
            $table->string('owner_name', 150)->nullable();
            $table->string('phone', 30)->nullable()->index();
            $table->string('email', 150)->nullable();
            $table->date('started_at')->nullable();                        // تاريخ بدء التعامل
            $table->enum('status', ['active', 'stopped', 'needs_followup', 'archived'])->default('active')->index();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('expected_avg_shipments')->nullable(); // متوسط الشحنات المتوقع يومياً
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 30)->unique();
            $table->string('original_name', 255);
            $table->string('disk', 30)->default('private');
            $table->string('stored_path', 500);
            $table->char('file_hash', 64)->index();                       // كشف إعادة رفع الملف نفسه
            $table->unsignedBigInteger('file_size')->default(0);
            $table->enum('file_type', ['xlsx', 'xls', 'pdf_text', 'pdf_scanned', 'pdf']);
            $table->string('report_type', 60);                            // daily_report, shipments_followup ...
            $table->date('work_date')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->enum('status', ['uploaded', 'processing', 'needs_review', 'approved', 'failed', 'cancelled'])
                ->default('uploaded')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('accepted_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->json('errors')->nullable();
            $table->json('column_mapping')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_finished_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_data');                                     // البيانات كما قُرئت من الملف
            $table->json('mapped_data')->nullable();                      // بعد المطابقة والتصحيح
            $table->enum('status', ['pending', 'valid', 'needs_review', 'invalid', 'duplicate', 'accepted', 'rejected'])
                ->default('pending');
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shipment_code', 80)->nullable()->index();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['import_batch_id', 'status']);
            $table->unique(['import_batch_id', 'row_number']);
        });

        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_number', 30)->unique();                // RPT-2026-000001
            $table->date('work_date');                                    // تاريخ العمل (منفصل عن تاريخ الإدخال created_at)
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // الموظف صاحب التقرير
            $table->enum('status', ['draft', 'pending_review', 'approved', 'rejected'])->default('draft');
            $table->enum('source', ['manual', 'import'])->default('manual');
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('total_shipments')->nullable();
            $table->unsignedInteger('contact_attempts_count')->nullable();
            $table->text('notes')->nullable();
            $table->text('inquiries')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['work_date', 'status']);
            $table->index(['user_id', 'work_date']);
        });

        Schema::create('report_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('shipments_count')->nullable();
            $table->unsignedInteger('contact_attempts')->nullable();
            $table->json('contact_times')->nullable();                    // ["09:15","11:40"]
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['daily_report_id', 'store_id']);
            $table->index('store_id');
        });

        // نسخة كاملة من التقرير قبل كل تعديل جوهري / إعادة تقديم
        Schema::create('report_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_report_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['daily_report_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_revisions');
        Schema::dropIfExists('report_stores');
        Schema::dropIfExists('daily_reports');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('stores');
    }
};
