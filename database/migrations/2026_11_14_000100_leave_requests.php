<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** طلبات الإجازة والأعذار: يقدّمها الموظف ويقرّرها المدير/المسؤول */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['leave', 'absence_excuse', 'late_excuse']);
            $table->enum('leave_type', ['leave', 'sick', 'mission', 'other'])->nullable();
            $table->date('from_date');
            $table->date('to_date');
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('leave_id')->nullable()->constrained('leaves')->nullOnDelete();
            $table->foreignId('override_id')->nullable()->constrained('attendance_overrides')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'from_date']);
        });

        $now = now();
        DB::table('system_settings')->insertOrIgnore([
            'key' => 'manager_whatsapp', 'value' => null, 'type' => 'string', 'group' => 'attendance',
            'label' => 'رقم واتساب المدير لطلبات الإجازة', 'description' => 'يظهر للموظف زر لتنبيه المدير بطلبه — اتركه فارغاً لإخفائه',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Cache::forget('system_settings.all');
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        DB::table('system_settings')->where('key', 'manager_whatsapp')->delete();
        Cache::forget('system_settings.all');
    }
};
