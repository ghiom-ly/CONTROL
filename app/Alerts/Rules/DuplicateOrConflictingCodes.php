<?php

namespace App\Alerts\Rules;

use App\Alerts\AlertRule;
use App\Alerts\Finding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** كود في تقارير قيد المراجعة لمتجرين مختلفين */
class DuplicateOrConflictingCodes implements AlertRule
{
    public function key(): string
    {
        return 'duplicate_or_conflicting_codes';
    }

    public function evaluate(array $params, CarbonImmutable $now): array
    {
        return DB::table('shipment_followups as f')->join('daily_reports as r', 'r.id', '=', 'f.daily_report_id')
            ->where('r.status', 'pending_review')->whereNull('r.deleted_at')->whereNotNull('f.store_id')
            ->selectRaw('f.shipment_code c, COUNT(DISTINCT f.store_id) n')->groupBy('f.shipment_code')->having('n', '>', 1)
            ->orderBy('c')->limit(\App\Alerts\AlertEngine::RULE_LIMIT)->get()
            ->map(fn ($r) => new Finding('code:'.$r->c, 'الكود '.$r->c.' مسجّل لـ '.$r->n.' متاجر في تقارير قيد المراجعة', null, 'code', null, $r->c))
            ->all();
    }
}
