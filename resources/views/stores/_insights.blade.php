{{-- أرقام المتجر من التقارير المعتمدة فقط --}}
@php $w = $insights['week']; @endphp
<div class="mt-5 grid gap-3 sm:grid-cols-4">
    <x-stat-card label="أوردرات آخر 7 أيام" :value="$w['current']" icon="box" tone="navy"/>
    <x-stat-card label="الأيام السبعة السابقة" :value="$w['previous']" icon="calendar" tone="gray"/>
    <x-stat-card label="الفرق" :value="$w['diff']" icon="chart" :tone="($w['diff'] ?? 0) < 0 ? 'red' : 'green'"/>
    <x-stat-card label="نسبة التغيّر %" :value="$w['percent']" icon="chart" :tone="($w['percent'] ?? 0) < 0 ? 'red' : 'green'"/>
</div>

<div class="mt-5 grid gap-5 lg:grid-cols-2">
    <div class="card">
        <h3 class="px-5 pt-4 font-bold text-navy-900">الأوردرات اليومية (آخر 14 يوماً)</h3>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>التاريخ</th><th>الأوردرات</th></tr></thead>
                <tbody>
                @foreach($insights['daily']->reverse() as $date => $count)
                    <tr>
                        <td class="tabular-nums">{{ \Illuminate\Support\Carbon::parse($date)->format('Y/m/d') }}</td>
                        <td class="tabular-nums">{!! $count === null ? '<span class="text-slate-400">غير متوفر</span>' : number_format($count) !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="space-y-5">
        <div class="card">
            <h3 class="px-5 pt-4 font-bold text-navy-900">الأكواد المفتوحة غير المُجاب عليها ({{ $insights['openCodes']->count() }})</h3>
            @if($insights['openCodes']->isEmpty())
                <x-empty-state title="لا توجد أكواد مفتوحة" icon="check-circle"/>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>الكود</th><th>آخر نتيجة</th><th>الحالة</th><th>آخر متابعة</th></tr></thead>
                        <tbody>
                        @foreach($insights['openCodes'] as $s)
                            <tr>
                                <td class="ltr text-right font-bold">{{ $s->code }}</td>
                                <td><x-badge :enum="$s->last_result"/></td>
                                <td><x-badge :enum="$s->processing_status"/></td>
                                <td class="text-xs text-slate-500">{{ $s->last_followup_at?->format('Y/m/d') ?? '—' }} {{ $s->lastFollowupUser?->name }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="card p-5">
            <h3 class="font-bold text-navy-900 mb-3">ملاحظات التقارير</h3>
            @forelse($insights['notes'] as $rs)
                <div class="border-b border-slate-100 py-2 text-sm">
                    <p>{{ $rs->notes }}</p>
                    <p class="text-xs text-slate-400">{{ $rs->report->work_date->format('Y/m/d') }} — {{ $rs->report->user->name }}</p>
                </div>
            @empty
                <p class="text-sm text-slate-400">لا توجد ملاحظات.</p>
            @endforelse
        </div>
    </div>
</div>
