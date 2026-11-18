<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** فروع شركة التوصيل: مسؤول الفرع ومسؤول المناديب — لإرسال أكواد المناديب اللي ما ردوش */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->string('manager_name', 100)->nullable();
            $t->string('manager_phone', 30)->nullable();
            $t->string('couriers_manager_name', 100)->nullable();
            $t->string('couriers_manager_phone', 30)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
