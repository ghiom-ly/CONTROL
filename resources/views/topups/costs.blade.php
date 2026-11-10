<x-layouts.app title="تكاليف كروت التعبئة">
    <x-page-header title="تكاليف كروت التعبئة" :subtitle="'شهر '.$month->format('Y-m').' — حسب تاريخ إدراج الكرت، وتشمل الكروت التي بُلّغ أنها لا تعمل'">
        <x-slot:actions>
            @include('attendance._month-filter')
            <a href="{{ route('topups.costs.export', ['month' => $month->format('Y-m')]) }}" class="btn btn-light">تصدير Excel</a>
            <a href="{{ route('topups.index') }}" class="btn btn-light">الطلبات</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <x-stat-card label="إجمالي التكلفة" :value="$costs['total'].' د.ل'" icon="chart" tone="navy"/>
        <x-stat-card label="عدد الكروت" :value="$costs['count']" icon="phone" tone="blue"/>
        <x-stat-card label="كروت لا تعمل" :value="$costs['faulty_amount'].' د.ل'" icon="alert" tone="red"/>
        <x-stat-card label="عدد الموظفين" :value="$costs['by_user']->count()" icon="users" tone="green"/>
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <div class="card">
            <h2 class="card-title px-5 pt-4">حسب الشبكة والفئة (د.ل)</h2>
            <div class="table-wrap"><table class="table text-sm">
                <thead><tr><th>الشبكة</th>@foreach(\App\Models\TopupRequest::AMOUNTS as $a)<th>فئة {{ $a }}</th>@endforeach<th>المجموع</th></tr></thead>
                <tbody>
                @foreach(\App\Models\TopupRequest::OPERATORS as $op => $label)
                    <tr><td class="font-bold">{{ $label }}</td>
                        @foreach(\App\Models\TopupRequest::AMOUNTS as $a)<td class="tabular-nums">{{ $costs['by_type'][$op][$a] }}</td>@endforeach
                        <td class="font-bold tabular-nums">{{ array_sum($costs['by_type'][$op]) }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
        <div class="card">
            <h2 class="card-title px-5 pt-4">حسب الموظف</h2>
            <div class="table-wrap"><table class="table text-sm">
                <thead><tr><th>الموظف</th><th>عدد الكروت</th><th>الإجمالي (د.ل)</th></tr></thead>
                <tbody>
                @forelse($costs['by_user'] as $u)
                    <tr><td class="font-bold">{{ $u['name'] }}</td><td class="tabular-nums">{{ $u['count'] }}</td><td class="tabular-nums">{{ $u['total'] }}</td></tr>
                @empty
                    <tr><td colspan="3" class="text-slate-400">لا توجد كروت في هذا الشهر.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
    </div>
</x-layouts.app>
