<?php

namespace App\Reports\Definitions;

use App\Enums\FollowupResult;
use App\Enums\IssueStatus;
use App\Reports\ReportData;
use App\Reports\ReportParams;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** تقرير موجّه لصاحب المتجر — بلا أسماء موظفين ولا ملاحظات داخلية */
class StoreReport extends BaseReport
{
    public function key(): string
    {
        return 'store';
    }

    public function title(): string
    {
        return 'تقرير المتجر';
    }

    public function audience(): string
    {
        return 'stores';
    }

    public function needs(): array
    {
        return ['store', 'period'];
    }

    public function build(ReportParams $p): ReportData
    {
        $s = $p->store;
        [$f, $t] = [$p->period->from->toDateString(), $p->period->to->toDateString()];
        [$a, $b] = [$p->period->from->startOfDay(), $p->period->to->endOfDay()];

        $daily = DB::table('store_daily_metrics')->where('store_id', $s->id)->whereBetween('metric_date', [$f, $t])
            ->orderBy('metric_date')->get(['metric_date', 'shipments_count']);
        $rate = DB::table('report_stores as rs')->join('daily_reports as r', 'r.id', '=', 'rs.daily_report_id')
            ->where('rs.store_id', $s->id)->where('r.status', 'approved')->whereNull('r.deleted_at')
            ->whereBetween('r.work_date', [$f, $t])->avg('rs.start_rate');
        $results = DB::table('shipment_followups')->where('store_id', $s->id)->where('is_official', true)
            ->whereBetween('followup_at', [$a, $b])->selectRaw('result, COUNT(*) n')->groupBy('result')->orderByDesc('n')->get();
        $open = DB::table('shipments')->where('store_id', $s->id)->whereNull('deleted_at')
            ->whereIn('processing_status', ['open', 'in_progress'])->orderBy('registered_at')->get(['code', 'last_result', 'registered_at']);
        $issues = DB::table('issues')->where('store_id', $s->id)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereBetween('reported_at', [$a, $b])->orWhereNotIn('status', ['resolved', 'closed']))
            ->orderByDesc('reported_at')->get(['title', 'status', 'reported_at']);
        $label = fn ($r) => $r ? FollowupResult::from($r)->label() : null;
        $today = CarbonImmutable::today();

        return new ReportData($this->title().' — '.$s->name, $this->periodLabel($p), [
            'الطلبيات' => (int) $daily->sum('shipments_count'),
            'متوسط نسبة التسليم' => $rate === null ? null : round((float) $rate, 2).'%',
            'المتابعات' => (int) $results->sum('n'),
            'الأكواد المفتوحة' => $open->count(),
            'المشكلات' => $issues->count(),
        ], [
            ['title' => 'الطلبيات اليومية', 'headers' => ['التاريخ', 'الطلبيات'],
                'rows' => $daily->map(fn ($d) => [substr((string) $d->metric_date, 0, 10), (int) $d->shipments_count])->all()],
            ['title' => 'نتائج المتابعات', 'headers' => ['النتيجة', 'العدد'],
                'rows' => $results->map(fn ($r) => [$label($r->result), (int) $r->n])->all()],
            ['title' => 'الأكواد المفتوحة', 'headers' => ['الكود', 'آخر نتيجة', 'العمر (أيام)'],
                'rows' => $open->map(fn ($c) => [$c->code, $label($c->last_result), (int) CarbonImmutable::parse($c->registered_at)->diffInDays($today)])->all()],
            ['title' => 'المشكلات', 'headers' => ['العنوان', 'الحالة', 'التاريخ'],
                'rows' => $issues->map(fn ($i) => [$i->title, IssueStatus::from($i->status)->label(), substr((string) $i->reported_at, 0, 10)])->all()],
        ], internal: false);
    }
}
