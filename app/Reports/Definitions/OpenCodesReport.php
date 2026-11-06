<?php

namespace App\Reports\Definitions;

use App\Reports\ReportData;
use App\Reports\ReportParams;
use Illuminate\Support\Facades\DB;

class OpenCodesReport extends BaseReport
{
    public function key(): string
    {
        return 'open_codes';
    }

    public function title(): string
    {
        return 'الأكواد المفتوحة';
    }

    public function needs(): array
    {
        return ['store?'];
    }

    public function build(ReportParams $p): ReportData
    {
        $today = today()->toDateString();
        $rows = DB::table('shipments as s')->leftJoin('stores as st', 'st.id', '=', 's.store_id')
            ->whereNull('s.deleted_at')->whereIn('s.processing_status', ['open', 'in_progress'])
            ->when($p->store, fn ($q, $store) => $q->where('s.store_id', $store->id))
            ->orderBy('st.name')->orderBy('s.registered_at')
            ->get(['s.code', 'st.name', 's.last_result', 's.followups_count', 's.registered_at'])
            ->map(fn ($r) => [$r->code, $r->name, $r->last_result ? \App\Enums\FollowupResult::from($r->last_result)->label() : null,
                (int) $r->followups_count, substr((string) $r->registered_at, 0, 10),
                (int) \Carbon\CarbonImmutable::parse($r->registered_at)->diffInDays($today)])->all();

        return new ReportData($this->title(), ($p->store ? 'المتجر: '.$p->store->name.' — ' : '').'حتى '.now()->format('Y/m/d'),
            ['عدد الأكواد المفتوحة' => count($rows)], [[
                'title' => 'الأكواد', 'landscape' => true,
                'headers' => ['الكود', 'المتجر', 'آخر نتيجة', 'عدد المتابعات', 'تاريخ التسجيل', 'العمر (أيام)'],
                'rows' => $rows,
            ]]);
    }
}
