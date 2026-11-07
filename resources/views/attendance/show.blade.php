<x-layouts.app :title="'حضور '.$user->name">
    @php $shiftNow = \App\Models\EmployeeShiftTime::currentFor($user->id); @endphp
    <x-page-header :title="'حضور '.$user->name" :subtitle="'شهر '.$month->format('Y-m').' — وقت بداية الشفت: '.($shiftNow ?? \App\Models\SystemSetting::get('attendance_late_after', '10:00').' (الوقت العام)')">
        <x-slot:actions>
            @include('attendance._month-filter')
            <a href="{{ route('attendance.print', [$user, 'month' => $month->format('Y-m')]) }}" target="_blank" class="btn btn-light">طباعة</a>
            <a href="{{ route('attendance.export', ['month' => $month->format('Y-m'), 'user_id' => $user->id]) }}" class="btn btn-light">تصدير Excel</a>
            <a href="{{ route('attendance.index', ['month' => $month->format('Y-m')]) }}" class="btn btn-light">كل الموظفين</a>
        </x-slot:actions>
    </x-page-header>

    @include('attendance._days')

    <div class="card p-5 mt-5 text-sm">
        <h2 class="card-title mb-2">الإجازات المسجّلة</h2>
        @forelse($leaves as $l)
            <div class="flex items-center gap-3 border-b border-slate-100 py-1">
                <span>{{ $l->from_date->format('Y-m-d') }} ← {{ $l->to_date->format('Y-m-d') }}</span>
                <span class="badge badge-blue">{{ \App\Models\Leave::TYPES[$l->type] }}</span>
                <span class="text-slate-500">{{ $l->notes }}</span>
                @can('attendance.manage')
                    <form method="POST" action="{{ route('attendance.leaves.destroy', $l) }}" class="ms-auto">@csrf @method('DELETE')
                        <button class="btn btn-ghost btn-sm text-red-600"><x-icon name="trash" class="size-4"/></button>
                    </form>
                @endcan
            </div>
        @empty
            <p class="text-slate-400">لا توجد إجازات.</p>
        @endforelse
    </div>
</x-layouts.app>
