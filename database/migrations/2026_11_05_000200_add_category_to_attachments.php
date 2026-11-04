<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** تصنيف المرفق داخل التقرير: courier_screens | customer_calls */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('category', 30)->nullable()->after('attachable_id');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', fn (Blueprint $table) => $table->dropColumn('category'));
    }
};
