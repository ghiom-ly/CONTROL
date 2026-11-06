<x-layouts.app title="مراجعة التقارير">
    <x-page-header title="مراجعة التقارير" subtitle="التقارير بانتظار الاعتماد — الأقدم أولاً"/>

    <div class="card">
        @if($reports->isEmpty())
            <x-empty-state title="لا توجد تقارير بانتظار المراجعة" icon="check-circle"/>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>التقرير</th><th>الموظف</th><th>تاريخ العمل</th><th>أُرسل</th><th>المتاجر</th><th>الأوردرات</th><th>تنبيهات</th><th></th></tr></thead>
                    <tbody>
                    @foreach($reports as $r)
                        <tr>
                            <td class="ltr text-right">{{ $r->report_number }} @if($r->isConfirmation())<span class="badge badge-blue">تأكيد طلبيات</span>@endif</td>
                            <td class="font-bold">{{ $r->user->name }} @if($r->is_legacy)<span class="badge badge-orange">تقرير قديم مستورد</span>@endif</td>
                            <td>{{ $r->work_date->format('Y/m/d') }}</td>
                            <td class="text-xs text-slate-500">{{ $r->submitted_at?->diffForHumans() }}</td>
                            <td class="tabular-nums">{{ $r->stores_count }}</td>
                            <td class="tabular-nums">{{ $r->total_shipments ?? 'غير متوفر' }}</td>
                            <td>
                                @foreach($r->warningFlags() as $f)<span class="badge badge-yellow">{{ \App\Models\DailyReport::WARNING_LABELS[$f] }}</span> @endforeach
                            </td>
                            <td class="text-left"><a href="{{ route('reports.show', $r) }}" class="btn btn-primary btn-sm">مراجعة</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $reports->links() }}
        @endif
    </div>
</x-layouts.app>
