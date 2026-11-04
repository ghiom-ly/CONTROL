<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 3: أسماء المتاجر البديلة، مطابقات الأعمدة المحفوظة، أرقام ملف المنظومة على التقرير،
 * ومصدر كل قيمة (file/manual)، والمتابعات التاريخية للتقارير القديمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 200);
            $table->string('normalized_alias', 200)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('import_mappings', function (Blueprint $table) {
            $table->id();
            $table->char('signature', 64)->unique();
            $table->enum('layout', ['orders', 'stores']);
            $table->json('columns');
            $table->json('delivered_values')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->foreignId('daily_report_id')->nullable()->after('report_type')->constrained()->nullOnDelete();
        });
        DB::statement("ALTER TABLE import_batches MODIFY file_type ENUM('xlsx','xls','csv','text','pdf_text','pdf_scanned','pdf') NOT NULL");

        Schema::table('daily_reports', function (Blueprint $table) {
            $table->boolean('is_legacy')->default(false)->after('source');
            $table->json('file_values')->nullable()->after('end_delivery_rate');
            $table->json('value_sources')->nullable()->after('file_values');
        });

        Schema::table('report_stores', function (Blueprint $table) {
            $table->string('shipments_count_source', 10)->nullable()->after('shipments_count');
            $table->unsignedInteger('file_orders_count')->nullable()->after('shipments_count_source');
            $table->unsignedInteger('file_delivered_count')->nullable()->after('file_orders_count');
            $table->unsignedInteger('end_file_orders_count')->nullable()->after('file_delivered_count');
            $table->unsignedInteger('end_file_delivered_count')->nullable()->after('end_file_orders_count');
        });

        Schema::table('shipment_followups', function (Blueprint $table) {
            $table->boolean('is_historical')->default(false)->after('is_official')->index();
        });
    }

    public function down(): void
    {
        Schema::table('shipment_followups', function (Blueprint $table) {
            $table->dropIndex(['is_historical']);
            $table->dropColumn('is_historical');
        });
        Schema::table('report_stores', fn (Blueprint $t) => $t->dropColumn([
            'shipments_count_source', 'file_orders_count', 'file_delivered_count', 'end_file_orders_count', 'end_file_delivered_count',
        ]));
        Schema::table('daily_reports', fn (Blueprint $t) => $t->dropColumn(['is_legacy', 'file_values', 'value_sources']));

        DB::table('import_batches')->whereIn('file_type', ['csv', 'text'])->delete();
        DB::statement("ALTER TABLE import_batches MODIFY file_type ENUM('xlsx','xls','pdf_text','pdf_scanned','pdf') NOT NULL");
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropForeign(['daily_report_id']);
            $table->dropColumn('daily_report_id');
        });

        Schema::dropIfExists('import_mappings');
        Schema::dropIfExists('store_aliases');
    }
};
