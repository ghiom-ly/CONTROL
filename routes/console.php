<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// نسخة احتياطية يومية الساعة 2 فجراً (يتطلب تشغيل: php artisan schedule:work أو cron)
\Illuminate\Support\Facades\Schedule::command('nours:backup')->dailyAt('02:00');

// تنبيهات الحضور للمسؤولين كل 15 دقيقة (المرحلة 4)
\Illuminate\Support\Facades\Schedule::command('nours:attendance-alerts')->everyFifteenMinutes();
