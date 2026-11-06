<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** المرحلة 7: التنبيهات التلقائية الناتجة عن قواعد alert_rules */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key', 80)->index();
            $table->enum('level', ['info', 'warning', 'important', 'critical'])->default('warning');
            $table->string('title', 255);
            $table->text('details')->nullable();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 200)->nullable();
            $table->string('fingerprint', 191)->index();
            $table->enum('status', ['open', 'closed'])->default('open')->index();
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('close_reason', ['auto', 'manual'])->nullable();
            $table->timestamps();
            $table->index(['rule_key', 'status']);
            // تنبيه مفتوح واحد فقط لكل (قاعدة، بصمة) — المغلقة NULL فلا تتعارض
            $table->string('open_key', 300)->nullable()
                ->storedAs("IF(status = 'open', CONCAT(rule_key, ':', fingerprint), NULL)")->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
