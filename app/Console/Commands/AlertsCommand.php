<?php

namespace App\Console\Commands;

use App\Alerts\AlertEngine;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AlertsCommand extends Command
{
    protected $signature = 'nours:alerts';

    protected $description = 'تشغيل قواعد التنبيه التلقائية وفتح/إغلاق التنبيهات';

    public function handle(AlertEngine $engine): int
    {
        $r = $engine->run(CarbonImmutable::now());
        $this->info("تنبيهات جديدة: {$r['opened']} — أُغلقت تلقائياً: {$r['closed']}");

        return self::SUCCESS;
    }
}
