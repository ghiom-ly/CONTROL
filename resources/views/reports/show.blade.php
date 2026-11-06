<x-layouts.app :title="'تقرير '.$report->report_number">
    <x-page-header :title="'ريبورت تاريخ '.$report->work_date->format('Y-m-d').' — '.$report->user->name"
                   :subtitle="$report->report_number.' — النسخة '.$report->version">
        <x-slot:actions>
            <x-badge :enum="$report->status"/>
            @if($report->is_legacy)<span class="badge badge-orange">تقرير قديم مستورد — يُعتمد كسجل تاريخي</span>@endif
            @can('update', $report)
                <a href="{{ route('reports.edit', $report) }}" class="btn btn-light"><x-icon name="pencil" class="size-4"/> تعديل</a>
            @endcan
            <button type="button" class="btn btn-light" x-data="{ done: false }"
                    x-on:click="navigator.clipboard.writeText($refs.wa.value); done = true; setTimeout(() => done = false, 2000)">
                <x-icon name="report" class="size-4"/> <span x-text="done ? 'تم النسخ ✓' : 'نسخ كنص واتساب'"></span>
                <textarea x-ref="wa" class="hidden">{{ $whatsapp }}</textarea>
            </button>
        </x-slot:actions>
    </x-page-header>

    @php $diffs = $report->fileDiscrepancies(); @endphp
    @if($diffs)
        <section class="card mb-5 border-amber-200">
            <h2 class="card-title px-5 pt-4">الفروق مع ملف المنظومة</h2>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>البند</th><th>في التقرير</th><th>في الملف</th></tr></thead>
                    <tbody>
                    @foreach($diffs as $d)
                        <tr><td class="font-bold">{{ $d['label'] }}</td><td class="tabular-nums">{{ $d['report'] ?? 'غير متوفر' }}</td><td class="tabular-nums">{{ $d['file'] ?? 'غير متوفر' }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if($flags)
        <div class="mb-5 flex flex-wrap gap-2">
            @foreach($flags as $f)<span class="badge badge-yellow">⚠ {{ \App\Models\DailyReport::WARNING_LABELS[$f] }}</span>@endforeach
        </div>
    @endif
    @if($report->rejection_reason)
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <b>سبب الإرجاع:</b> {{ $report->rejection_reason }}
            @if($report->status === \App\Enums\ReportStatus::Rejected && $report->user_id === auth()->id())
                @can('update', $report)
                    <a href="{{ route('reports.edit', $report) }}" class="btn btn-accent btn-sm ms-2"><x-icon name="pencil" class="size-4"/> عدّل التقرير وأعد إرساله</a>
                @else
                    <p class="mt-1 text-red-700">تعديل التقارير المرجعة موقوف من الإعدادات — تواصل مع المشرف ليصحّحه.</p>
                @endcan
            @endif
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-5">
            <section class="card p-5 grid gap-3 sm:grid-cols-4 text-sm">
                <div><p class="label">إجمالي الطلبيات</p><p class="font-bold">{{ $report->start_total_orders ?? 'غير متوفر' }}</p></div>
                <div><p class="label">التسليم عند البداية</p><p class="font-bold">{{ $report->start_delivery_rate !== null ? $report->start_delivery_rate.'%' : 'غير متوفر' }}</p></div>
                <div><p class="label">التسليم النهائي</p><p class="font-bold">{{ $report->end_delivery_rate !== null ? $report->end_delivery_rate.'%' : 'غير متوفر' }}</p></div>
                <div><p class="label">التحسّن</p><p class="font-bold">{{ ($report->start_delivery_rate !== null && $report->end_delivery_rate !== null) ? round($report->end_delivery_rate - $report->start_delivery_rate, 2).'%' : 'غير متوفر' }}</p></div>
            </section>

            <section class="card">
                <h2 class="card-title px-5 pt-4">① المتاجر ({{ $report->stores->count() }}) — الإجمالي: {{ $report->total_shipments ?? 'غير متوفر' }}</h2>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>المتجر</th><th>طلبيات البداية</th><th>نسبة البداية</th><th>اشتغلت عليها</th><th>الأكواد</th><th>ملاحظة</th></tr></thead>
                        <tbody>
                        @foreach($report->stores as $rs)
                            <tr>
                                <td class="font-bold">{{ $rs->store->name }} @if($rs->is_outside_assignment)<span class="badge badge-yellow">خارج الإسناد</span>@endif</td>
                                <td class="tabular-nums">{{ $rs->start_orders ?? '—' }}</td>
                                <td class="tabular-nums">{{ $rs->start_rate !== null ? $rs->start_rate.'%' : '—' }}</td>
                                <td class="tabular-nums">{{ $rs->shipments_count ?? 'غير متوفر' }}</td>
                                <td class="text-xs">
                                    @foreach($report->followups->where('store_id', $rs->store_id) as $f)
                                        <x-code :code="$f->shipment_code"/> <x-badge :enum="$f->result"/><br>
                                    @endforeach
                                </td>
                                <td class="text-sm">{{ $rs->notes ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card p-5 text-sm">
                <h2 class="card-title mb-2">② المحاولات: {{ count($report->attempt_times ?? []) }}</h2>
                <p>{{ implode(' — ', $report->attempt_times ?? []) ?: 'لا يوجد' }}</p>
                @if($report->attempts_over_limit_reason)<p class="mt-1 text-amber-700">سبب التجاوز: {{ $report->attempts_over_limit_reason }}</p>@endif
            </section>

            <section class="card p-5 text-sm space-y-2">
                <h2 class="card-title">③ الأكواد غير المُجاب عليها</h2>
                <p><b>الزبون لم يرد:</b> @forelse($report->followups->where('result', \App\Enums\FollowupResult::CustomerNoAnswer) as $f)<x-code :code="$f->shipment_code"/> @empty لا يوجد @endforelse</p>
                <p><b>المندوب لم يرد:</b> @forelse($report->followups->where('result', \App\Enums\FollowupResult::CourierNoAnswer) as $f)<x-code :code="$f->shipment_code"/> @empty لا يوجد @endforelse</p>
            </section>

            <section class="card p-5 text-sm space-y-2">
                <h2 class="card-title">④ ملاحظات واستفسارات</h2>
                <p>{{ $report->notes ?? '—' }}</p>
                <p>{{ $report->inquiries ?? '' }}</p>
                @foreach($report->issue_requests ?? [] as $iss)
                    <p class="badge badge-orange">مشكلة: {{ $iss['title'] }}</p>
                @endforeach
            </section>

            @include('reports._screens', ['editable' => false])
            @includeWhen(View::exists('reports._review'), 'reports._review')
        </div>

        <aside class="space-y-5">
            <section class="card p-5 text-sm">
                <h2 class="card-title mb-2">نص واتساب</h2>
                <pre class="whitespace-pre-wrap font-sans leading-7 text-slate-700">{{ $whatsapp }}</pre>
            </section>
            <section class="card p-5 text-sm">
                <h2 class="card-title mb-2">سجل النسخ</h2>
                @forelse($report->revisions as $rev)
                    <p class="border-b border-slate-100 py-1">النسخة {{ $rev->version }} — {{ $rev->reason }} — {{ $rev->changer?->name }} — {{ $rev->created_at?->format('Y/m/d H:i') }}</p>
                @empty
                    <p class="text-slate-400">لا توجد نسخ سابقة.</p>
                @endforelse
            </section>
        </aside>
    </div>
</x-layouts.app>
