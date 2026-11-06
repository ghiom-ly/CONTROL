<x-layouts.app title="التحليلات">
    <x-page-header title="مركز التحليلات" :subtitle="$p->label()">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                @foreach(['day' => 'يوم', 'week' => 'أسبوع', 'month' => 'شهر'] as $k => $l)
                    <a href="{{ route('analytics.index', ['period' => $k]) }}" class="btn {{ $p->preset === $k ? 'btn-primary' : 'btn-light' }}">{{ $l }}</a>
                @endforeach
                <form method="GET" class="flex items-center gap-1" data-no-loading>
                    <input type="hidden" name="period" value="custom">
                    <input type="date" name="from" value="{{ $p->from->toDateString() }}" class="input !w-36">
                    <input type="date" name="to" value="{{ $p->to->toDateString() }}" class="input !w-36">
                    <button class="btn {{ $p->preset === 'custom' ? 'btn-primary' : 'btn-light' }}">مخصصة</button>
                </form>
            </div>
        </x-slot:actions>
    </x-page-header>
    @error('to')<p class="field-error mb-3">{{ $message }}</p>@enderror

    @includeIf('analytics._charts')

    <section class="card mb-5">
        <div class="card-header"><h2 class="card-title">هل الشغل يتحسن؟</h2></div>
        @include('analytics._compare')
    </section>

    <section class="card mb-5">
        <div class="card-header"><h2 class="card-title">أي المتاجر تتراجع؟</h2></div>
        <div class="grid gap-5 p-5 lg:grid-cols-3 text-sm">
            <div>
                <h3 class="font-bold mb-2">أكثر نقصاً في الطلبيات</h3>
                @forelse($declining as $r)
                    <p class="flex justify-between border-b border-slate-100 py-1"><a class="text-navy-700" href="{{ route('stores.show', $r['store']) }}">{{ $r['store']->name }}</a>
                        <span class="tabular-nums text-red-700">{{ $r['previous'] }} ← {{ $r['current'] }} ({{ $r['percent'] }}%)</span></p>
                @empty <p class="text-slate-500">لا توجد بيانات</p> @endforelse
            </div>
            <div>
                <h3 class="font-bold mb-2">انخفاض نسبة التسليم</h3>
                @forelse($rateDrops as $r)
                    <p class="flex justify-between border-b border-slate-100 py-1"><a class="text-navy-700" href="{{ route('stores.show', $r['store']) }}">{{ $r['store']->name }}</a>
                        <span class="tabular-nums text-red-700">{{ $r['previous'] }}% ← {{ $r['current'] }}%</span></p>
                @empty <p class="text-slate-500">لا توجد بيانات</p> @endforelse
            </div>
            <div>
                <h3 class="font-bold mb-2">توقفت في هذه الفترة</h3>
                @forelse($stopped as $s)
                    <p class="border-b border-slate-100 py-1"><a class="text-navy-700" href="{{ route('stores.show', $s) }}">{{ $s->name }}</a></p>
                @empty <p class="text-slate-500">لا توجد بيانات</p> @endforelse
            </div>
        </div>
    </section>

    <section class="card mb-5" x-data="{ sort: 'improvement', dir: 1 }">
        <div class="card-header"><h2 class="card-title">أي الموظفين أداؤهم أقل؟</h2><span class="text-xs text-slate-500">مرتب بالتحسن تصاعدياً (الأضعف أولاً)</span></div>
        @if($employees->isEmpty())
            <p class="p-5 text-slate-500">لا توجد بيانات</p>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>الموظف</th><th>التقارير المعتمدة</th><th>الطلبيات</th><th>متوسط التحسن</th><th>رد الزبون</th><th>رد المندوب</th><th>تأخير</th><th>غياب</th></tr></thead>
                <tbody>
                @foreach($employees as $e)
                    <tr>
                        <td class="font-bold">{{ $e['user']->name }}</td>
                        <td class="tabular-nums">{{ $e['reports'] }}</td>
                        <td class="tabular-nums">{{ $e['orders'] }}</td>
                        <td class="tabular-nums {{ $e['improvement'] !== null && $e['improvement'] < 0 ? 'text-red-700' : '' }}">{{ $e['improvement'] ?? '—' }}</td>
                        <td class="tabular-nums">{{ $e['customer_rate'] !== null ? $e['customer_rate'].'%' : '—' }}</td>
                        <td class="tabular-nums">{{ $e['courier_rate'] !== null ? $e['courier_rate'].'%' : '—' }}</td>
                        <td class="tabular-nums">{{ $e['late'] }}</td>
                        <td class="tabular-nums">{{ $e['absent'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </section>

    <section class="card mb-5">
        <div class="card-header"><h2 class="card-title">ما المشاكل المتكررة؟</h2>
            <span class="text-sm text-slate-600">متوسط مدة الحل: <b>{{ $issues['avg_resolution_hours'] !== null ? $issues['avg_resolution_hours'].' ساعة' : '—' }}</b> — المتأخرة الآن: <b class="text-red-700">{{ $issues['overdue_now'] }}</b></span></div>
        <div class="grid gap-5 p-5 lg:grid-cols-2 text-sm">
            <div>
                <h3 class="font-bold mb-2">حسب النوع</h3>
                @forelse($issues['by_type'] as $label => $n)
                    <p class="flex justify-between border-b border-slate-100 py-1"><span>{{ $label }}</span><span class="tabular-nums">{{ $n }}</span></p>
                @empty <p class="text-slate-500">لا توجد بيانات</p> @endforelse
            </div>
            <div>
                <h3 class="font-bold mb-2">أكثر المتاجر تكراراً</h3>
                @forelse($issues['top_stores'] as $r)
                    <p class="flex justify-between border-b border-slate-100 py-1"><a class="text-navy-700" href="{{ route('issues.index', ['store_id' => $r['store']->id]) }}">{{ $r['store']->name }}</a><span class="tabular-nums">{{ $r['count'] }}</span></p>
                @empty <p class="text-slate-500">لا توجد بيانات</p> @endforelse
            </div>
        </div>
    </section>
</x-layouts.app>
