@php
    $fmt = fn ($v, $unit) => $v === null ? '—' : ($unit === 'percent' ? number_format($v, 2).'%' : ($unit === 'points' ? number_format($v, 2) : number_format($v)));
@endphp
<div class="table-wrap"><table class="table">
    <thead><tr><th>المؤشر</th><th>السابق</th><th>الحالي</th><th>الفرق</th><th>النسبة</th></tr></thead>
    <tbody>
    @foreach($compare as $r)
        @php
            $good = $r['diff'] === null || $r['diff'] == 0 ? null : (($r['diff'] > 0) !== $r['lower_is_better']);
            $tone = $good === null ? 'text-slate-500' : ($good ? 'text-green-700' : 'text-red-700');
            $arrow = $r['diff'] === null || $r['diff'] == 0 ? '' : ($r['diff'] > 0 ? '▲' : '▼');
        @endphp
        <tr>
            <td class="font-semibold">{{ $r['label'] }}</td>
            <td class="tabular-nums">{{ $fmt($r['previous'], $r['unit']) }}</td>
            <td class="tabular-nums font-bold">{{ $fmt($r['current'], $r['unit']) }}</td>
            <td class="tabular-nums {{ $tone }}">{{ $arrow }} {{ $r['diff'] === null ? '—' : ($r['diff'] > 0 ? '+' : '').number_format($r['diff'], $r['unit'] === 'count' ? 0 : 2) }}</td>
            <td class="tabular-nums {{ $tone }}">{{ $r['percent'] === null ? '—' : ($r['percent'] > 0 ? '+' : '').$r['percent'].'%' }}</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
