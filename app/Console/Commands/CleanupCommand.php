<?php

namespace App\Console\Commands;

use App\Models\Alert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** تنظيف يومي: التنبيهات المغلقة منذ أكثر من 90 يوماً + صفوف الكاش المنتهية */
class CleanupCommand extends Command
{
    protected $signature = 'nours:cleanup {--days=90}';

    protected $description = 'حذف التنبيهات المغلقة القديمة وصفوف الكاش المنتهية';

    public function handle(): int
    {
        $alerts = Alert::where('status', 'closed')->where('closed_at', '<', now()->subDays(max(1, (int) $this->option('days'))))->delete();
        $cache = config('cache.default') === 'database'
            ? DB::table(config('cache.stores.database.table', 'cache'))->where('expiration', '<', time())->delete() : 0;
        $this->info("alerts: {$alerts}, cache: {$cache}");

        return self::SUCCESS;
    }
}
