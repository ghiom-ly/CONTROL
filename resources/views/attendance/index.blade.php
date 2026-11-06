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

    @if(auth()->user()->isSuperAdmin())
        @php $holidayList = \App\Models\Holiday::orderByDesc('date')->limit(50)->get(); @endphp
        <div class="card mt-5 p-5 text-sm">
            <h2 class="card-title mb-3">العطل الرسمية (لا تُحسب غياباً لأحد)</h2>
            <form method="POST" action="{{ route('attendance.holidays.store') }}" class="mb-3 flex flex-wrap items-end gap-2">
                @csrf
                <div><label class="label">التاريخ</label><input type="date" name="date" required class="input"></div>
                <div><label class="label">الاسم</label><input name="name" required maxlength="120" class="input" placeholder="مثال: عيد الفطر"></div>
                <button class="btn btn-primary">إضافة عطلة</button>
                @error('date')<p class="field-error w-full">{{ $message }}</p>@enderror
            </form>
            @forelse($holidayList as $hd)
                <form method="POST" action="{{ route('attendance.holidays.destroy', $hd) }}" class="flex items-center justify-between border-b border-slate-100 py-1">
                    @csrf @method('DELETE')
                    <span><span class="tabular-nums">{{ $hd->date->format('Y/m/d') }}</span> — {{ $hd->name }}</span>
                    <button class="text-red-600 underline">حذف</button>
                </form>
            @empty <p class="text-slate-500">لا توجد عطل مسجلة.</p> @endforelse
            <p class="hint mt-2">لإلغاء غياب أو تأخير موظف معيّن: افتح صفحة حضوره واستخدم عمود «تحكم المدير».</p>
        </div>
    @endif
</x-layouts.app>
