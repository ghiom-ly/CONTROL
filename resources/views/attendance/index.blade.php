<x-layouts.app title="الحضور والانصراف">
    @php $h = fn ($m) => $m === null ? '—' : sprintf('%d:%02d', intdiv($m, 60), $m % 60); @endphp
    <x-page-header title="الحضور والانصراف" :subtitle="'شهر '.$month->format('Y-m').' — الحضور = بدء الشفت، الانصراف = أول إرسال للتقرير'">
        <x-slot:actions>
            @include('attendance._month-filter')
            <a href="{{ route('attendance.export', ['month' => $month->format('Y-m')]) }}" class="btn btn-light"><x-icon name="document-chart" class="size-4"/> تصدير Excel</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>الموظف</th><th>حضور</th><th>غياب</th><th>إجازات</th><th>تأخير</th><th>دقائق التأخير</th><th>غير مكتمل</th><th>متوسط الساعات</th><th>التقارير (معتمد/مرفوض)</th><th></th></tr></thead>
                <tbody>
                @forelse($rows as ['user' => $u, 'summary' => $s])
                    <tr>
                        <td class="font-bold">{{ $u->name }}</td>
                        <td class="tabular-nums">{{ $s['present'] }}</td>
                        <td class="tabular-nums {{ $s['absent'] ? 'text-red-700 font-bold' : '' }}">{{ $s['absent'] }}</td>
                        <td class="tabular-nums">{{ $s['leave'] }}</td>
                        <td class="tabular-nums {{ $s['late'] ? 'text-amber-700 font-bold' : '' }}">{{ $s['late'] }}</td>
                        <td class="tabular-nums">{{ $s['late_minutes'] }}</td>
                        <td class="tabular-nums">{{ $s['incomplete'] }}</td>
                        <td class="tabular-nums">{{ $h($s['avg_minutes']) }}</td>
                        <td class="tabular-nums">{{ $s['reports_total'] }} ({{ $s['reports_approved'] }}/{{ $s['reports_rejected'] }})</td>
                        <td class="text-left whitespace-nowrap">
                            <a href="{{ route('attendance.show', [$u, 'month' => $month->format('Y-m')]) }}" class="btn btn-light btn-sm">التفاصيل</a>
                            <a href="{{ route('attendance.print', [$u, 'month' => $month->format('Y-m')]) }}" target="_blank" class="btn btn-light btn-sm">طباعة</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10"><x-empty-state title="لا يوجد موظفون" icon="users"/></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @can('attendance.manage')
        <form method="POST" action="{{ route('attendance.leaves.store') }}" class="card p-5 mt-5 space-y-3">
            @csrf
            <h2 class="card-title">تسجيل إجازة</h2>
            <div class="grid gap-3 sm:grid-cols-5">
                <select name="user_id" class="input" required>
                    <option value="">— الموظف —</option>
                    @foreach($employees as $e)<option value="{{ $e->id }}" @selected(old('user_id') == $e->id)>{{ $e->name }}</option>@endforeach
                </select>
                <input type="date" name="from_date" value="{{ old('from_date') }}" required class="input" title="من">
                <input type="date" name="to_date" value="{{ old('to_date') }}" required class="input" title="إلى">
                <select name="type" class="input">
                    @foreach(\App\Models\Leave::TYPES as $k => $l)<option value="{{ $k }}" @selected(old('type') === $k)>{{ $l }}</option>@endforeach
                </select>
                <input name="notes" value="{{ old('notes') }}" class="input" placeholder="ملاحظة" maxlength="500">
            </div>
            @foreach(['user_id', 'from_date', 'to_date', 'type'] as $f) @error($f)<p class="field-error">{{ $message }}</p>@enderror @endforeach
            <button class="btn btn-primary">حفظ الإجازة</button>
        </form>
    @endcan
</x-layouts.app>
