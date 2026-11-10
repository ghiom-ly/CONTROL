<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** طلبات كروت التعبئة (ليبيانا/مدار): الشراء خارج المنظومة، والمدير العام يدرج رقم الكرت عند الموافقة */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topup_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('operator', ['libyana', 'madar']);
            $table->unsignedTinyInteger('amount');
            $table->string('note', 300)->nullable();
            $table->enum('status', ['pending', 'issued', 'used', 'faulty', 'rejected', 'cancelled'])->default('pending')->index();
            $table->text('card_number')->nullable();                 // مشفّر
            $table->char('card_hash', 64)->nullable()->unique();     // لمنع إدراج نفس الكرت مرتين
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->index();
            $table->string('decision_note', 300)->nullable();
            $table->timestamp('used_at')->nullable();
            $table->string('employee_note', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topup_requests');
    }
};
