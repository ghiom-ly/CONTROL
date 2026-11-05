@php
    $h = fn ($m) => $m === null ? '—' : sprintf('%d:%02d', intdiv($m, 60), $m % 60);
    $colors = ['present' => 'green', 'late' => 'yellow', 'incomplete' => 'orange', 'absent' => 'red', 'leave' => 'blue', 'future' => 'gray', 'not_counted' => 'gray'];
    $s = $data['summary'];
@endphp
<div class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-5">
    <x-stat-card label="أيام الحضور" :value="$s['present']" icon="check-circle" tone="green"/>
    <x-stat-card label="الغياب" :value="$s['absent']" icon="user-x" tone="red"/>
    <x-stat-card label="مرات التأخير" :value="$s['late']" icon="clock" tone="yellow" :hint="$s['late_minutes'].' دقيقة'"/>
    <x-stat-card label="متوسط ساعات العمل" :value="$h($s['avg_minutes'])" icon="calendar" tone="navy"/>
    <x-stat-card label="الإجازات" :value="$s['leave']" icon="calendar" tone="blue"/>
    <x-stat-card label="شفتات غير مكتملة" :value="$s['incomplete']" icon="alert" tone="orange"/>
    <x-stat-card label="التقارير المعتمدة" :value="$s['reports_approved'].' / '.$s['reports_total']" icon="report" tone="green"/>
    <x-stat-card label="التقارير المرفوضة" :value="$s['reports_rejected']" icon="x" tone="red"/>
</div>
<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>التاريخ</th><th>الحالة</th><th>الحضور</th><th>الانصراف</th><th>المدة</th><th>التأخير</th></tr></thead>
            <tbody>
            @foreach($data['days'] as $d)
                <tr>
                    <td class="tabular-nums">{{ $d['date'] }}</td>
                    <td>
                        <span class="badge badge-{{ $colors[$d['status']] }}">{{ \App\Services\AttendanceService::STATUS_LABELS[$d['status']] }}</span>
                        @if($d['late_minutes'] && $d['status'] !== 'late')<span class="badge badge-yellow">متأخر</span>@endif
                        @if($d['leave_type'])<span class="text-xs text-slate-500">{{ $d['leave_type'] }}</span>@endif
                        @if($d['legacy'])<span class="text-xs text-slate-500">(تقرير قديم مستورد)</span>@endif
                    </td>
                    <td class="tabular-nums">{{ $d['in'] ?? '—' }}</td>
                    <td class="tabular-nums">{{ $d['out'] ?? '—' }}</td>
                    <td class="tabular-nums">{{ $h($d['minutes']) }}</td>
                    <td class="tabular-nums">{{ $d['late_minutes'] ? $d['late_minutes'].' د' : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
