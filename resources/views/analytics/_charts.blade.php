{{-- الرسوم الستة — $charts من AnalyticsService::charts() --}}
@php
    $has = [
        'rate_trend' => collect($charts['rate_trend']['start'])->merge($charts['rate_trend']['end'])->filter(fn ($v) => $v !== null)->isNotEmpty(),
        'orders' => array_sum($charts['orders']['current']) + array_sum($charts['orders']['previous']) > 0,
        'results' => $charts['results']['values'] !== [],
        'declining' => $charts['declining']['values'] !== [],
        'employees' => $charts['employees']['labels'] !== [],
        'issues' => $charts['issues']['values'] !== [],
    ];
    $titles = [
        'rate_trend' => 'نسبة التسليم يوماً بيوم', 'orders' => 'الطلبيات: الحالية مقابل السابقة', 'results' => 'نتائج المتابعات',
        'declining' => 'أكثر المتاجر تراجعاً (%)', 'employees' => 'مقارنة الموظفين', 'issues' => 'المشكلات حسب النوع',
    ];
@endphp
<div class="mb-5 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
    @foreach($titles as $key => $title)
        <section class="card p-4">
            <h3 class="mb-2 text-sm font-bold text-navy-900">{{ $title }}</h3>
            @if($has[$key])
                <div class="relative h-56"><canvas id="chart-{{ $key }}"></canvas></div>
            @else
                @if(in_array($key, ['employees', 'rate_trend'], true))
                    {{-- هذان الرسمان يعتمدان على تقارير المتابعة المعتمدة فقط --}}
                    <div class="flex h-56 flex-col items-center justify-center gap-1 px-4 text-center text-sm text-slate-400">
                        <p>لا توجد تقارير متابعة معتمدة في هذه الفترة.</p>
                        <p class="text-xs">تظهر المقارنة بعد اعتماد التقارير من صفحة المراجعة، أو اختر فترة أطول.</p>
                    </div>
                @else
                    <p class="flex h-56 items-center justify-center text-sm text-slate-400">لا توجد بيانات في هذه الفترة</p>
                @endif
            @endif
        </section>
    @endforeach
</div>
<script type="application/json" id="nours-charts">@json($charts)</script>
