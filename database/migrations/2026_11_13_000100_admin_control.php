<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** تحكم المدير العام: نوع خدمة المتجر، استثناءات الحضور، العطل الرسمية */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->enum('service_type', ['both', 'followup', 'confirmation'])->default('both')->after('status')->index();
        });
        Schema::create('attendance_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('type', ['excused', 'present', 'late_excused']);
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'date', 'type']);
        });
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 120);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('attendance_overrides');
        Schema::table('stores', fn (Blueprint $t) => $t->dropColumn('service_type'));
    }
};
