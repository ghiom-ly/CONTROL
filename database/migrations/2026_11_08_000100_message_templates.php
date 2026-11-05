<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** المرحلة 5: قوالب رسائل المتاجر، سجل الرسائل، صلاحيتا الإرسال والاعتماد، والقوالب المقترحة */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->enum('type', ['open_codes', 'issue', 'performance', 'general']);
            $table->text('body');
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'disabled'])->default('draft')->index();
            $table->unsignedInteger('version')->default(0);
            $table->text('review_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('store_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_template_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('template_version')->nullable();
            $table->string('type', 20);
            $table->text('body');
            $table->string('phone', 20);
            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'created_at']);
        });

        $now = now();
        foreach ([['messages.send', 'كتابة القوالب وإرسال الرسائل للمتاجر'], ['messages.approve', 'اعتماد قوالب الرسائل']] as [$name, $label]) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'display_name' => $label, 'group' => 'رسائل المتاجر', 'created_at' => $now, 'updated_at' => $now]);
        }
        $admin = DB::table('roles')->where('name', 'admin')->value('id');
        $send = DB::table('permissions')->where('name', 'messages.send')->value('id');
        if ($admin && $send) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $send]);
        }

        foreach (self::SUGGESTED as [$name, $type, $body]) {
            DB::table('message_templates')->insert(['name' => $name, 'type' => $type, 'body' => $body, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', ['messages.send', 'messages.approve'])->delete();
        Schema::dropIfExists('store_messages');
        Schema::dropIfExists('message_templates');
    }

    private const SUGGESTED = [
        ['أكواد لم يرد عليها الزبون', 'open_codes', "السلام عليكم {صاحب_المتجر}،\nمعكم {اسم_الشركة} — متابعة طلبيات {اسم_المتجر} بتاريخ {التاريخ}.\nالطلبيات التالية لم يرد أصحابها على المندوب ({عدد_الأكواد}):\n{الأكواد}\nنرجو التواصل مع الزبائن وتأكيد موعد التسليم أو العنوان.\nشكراً لتعاونكم — {اسم_المرسل}"],
        ['مشكلة مسجّلة على المتجر', 'issue', "السلام عليكم {صاحب_المتجر}،\nنود إبلاغكم بملاحظة على {اسم_المتجر} (رقم {رقم_المشكلة} بتاريخ {تاريخ_المشكلة}):\n{عنوان_المشكلة}\n{وصف_المشكلة}\nنرجو الرد بما يلزم لمعالجتها. — {اسم_المرسل}، {اسم_الشركة}"],
        ['ملخص أداء المتجر', 'performance', "السلام عليكم {صاحب_المتجر}،\nملخص أداء {اسم_المتجر} خلال {الفترة}:\nعدد الطلبيات: {عدد_الطلبيات}\nنسبة التسليم: من {نسبة_البداية} إلى {نسبة_النهاية} (التغير {التحسن})\nطلبيات مفتوحة تحتاج متابعة: {الأكواد_المفتوحة}\nشكراً لشراكتكم — {اسم_الشركة}"],
        ['رسالة عامة', 'general', "السلام عليكم {صاحب_المتجر}،\n...\nمع التحية — {اسم_الشركة}"],
    ];
};
