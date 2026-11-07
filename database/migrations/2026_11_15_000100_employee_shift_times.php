<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** وقت بداية الشفت لكل موظف — سجل بتاريخ سريان؛ start_time فارغ = الرجوع للوقت العام */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_shift_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('start_time', 5)->nullable();
            $table->date('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_shift_times');
    }
};
