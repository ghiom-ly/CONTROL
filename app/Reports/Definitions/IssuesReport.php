<?php

namespace App\Reports\Definitions;

use App\Models\Issue;
use App\Reports\ReportData;
use App\Reports\ReportParams;

class IssuesReport extends BaseReport
{
    public function key(): string
    {
        return 'issues';
    }

    public function title(): string
    {
        return 'المشكلات';
    }

    public function build(ReportParams $p): ReportData
    {
        [$a, $b] = [$p->period->from->startOfDay(), $p->period->to->endOfDay()];
        $issues = Issue::with(['store:id,name', 'assignee:id,name'])
            ->where(fn ($q) => $q->whereBetween('reported_at', [$a, $b])->orWhereNotIn('status', ['resolved', 'closed']))
            ->orderByDesc('reported_at')->get();

        $rows = $issues->map(fn (Issue $i) => [
            $i->issue_number, $i->store?->name, $i->type->label(), $i->priority->label(), $i->status->label(), $i->assignee?->name,
            $i->reported_at?->format('Y/m/d H:i'),
            $i->resolved_at ? round($i->reported_at->diffInMinutes($i->resolved_at) / 60, 1) : null,
            $i->isOverdue() ? 'نعم' : 'لا',
        ])->all();
        $resolved = $issues->filter(fn ($i) => $i->resolved_at);

        return new ReportData($this->title(), $this->periodLabel($p).' + المفتوحة حالياً', [
            'الإجمالي' => $issues->count(), 'المحلولة' => $resolved->count(),
            'المتأخرة' => $issues->filter->isOverdue()->count(),
            'متوسط الحل (ساعة)' => $resolved->isEmpty() ? null : round($resolved->avg(fn ($i) => $i->reported_at->diffInMinutes($i->resolved_at)) / 60, 1),
        ], [[
            'title' => 'المشكلات', 'landscape' => true,
            'headers' => ['الرقم', 'المتجر', 'النوع', 'الأولوية', 'الحالة', 'المسؤول', 'تاريخ التبليغ', 'مدة الحل (ساعة)', 'متأخرة'],
            'rows' => $rows,
        ]]);
    }
}
