<x-layouts.app :title="'تقرير التأكيد '.$report->report_number">
    <x-page-header :title="'تقرير تأكيد الطلبيات — '.$report->work_date->format('Y/m/d').' — '.$report->user->name" :subtitle="$report->report_number.' — النسخة '.$report->version">
        <x-slot:actions>
            <x-badge :enum="$report->status"/>
            @can('update', $report)
                <a href="{{ route('reports.edit', $report) }}" class="btn btn-light"><x-icon name="pencil" class="size-4"/> تعديل</a>
            @endcan
            <button type="button" class="btn btn-light" x-data="{ done: false }"
                    x-on:click="navigator.clipboard.writeText($refs.wa.value); done = true; setTimeout(() => done = false, 2000)">
                <x-icon name="report" class="size-4"/> <span x-text="done ? 'تم النسخ ✓' : 'نسخ كنص واتساب'">نسخ كنص واتساب</span>
                <textarea x-ref="wa" class="hidden">{{ $whatsapp }}</textarea>
            </button>
        </x-slot:actions>
    </x-page-header>

    @if($report->rejection_reason)
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <b>سبب الإرجاع:</b> {{ $report->rejection_reason }}
            @if($report->status === \App\Enums\ReportStatus::Rejected && $report->user_id === auth()->id())
                @can('update', $report)
                    <a href="{{ route('reports.edit', $report) }}" class="btn btn-accent btn-sm ms-2"><x-icon name="pencil" class="size-4"/> عدّل التقرير وأعد إرساله</a>
                @endcan
            @endif
        </div>
    @endif

    @php
        $orders = $report->confirmationOrders;
        $count = fn ($r) => $orders->filter(fn ($o) => $o->result->value === $r)->count();
    @endphp
    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card p-5">
                <h2 class="card-title mb-3">ملخص حركة الأوردرات</h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 text-center text-sm">
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">الإجمالي</p><p class="text-xl font-bold">{{ $orders->count() }}</p></div>
                    <div class="rounded-xl bg-green-50 p-3"><p class="text-green-700">المؤكدة</p><p class="text-xl font-bold">{{ $count('confirmed') }}</p></div>
                    <div class="rounded-xl bg-red-50 p-3"><p class="text-red-700">الملغاة</p><p class="text-xl font-bold">{{ $count('cancelled') }}</p></div>
                    <div class="rounded-xl bg-amber-50 p-3"><p class="text-amber-700">لا يرد</p><p class="text-xl font-bold">{{ $count('no_answer') }}</p></div>
                </div>
            </section>

            @forelse($orders->groupBy('store_id') as $items)
                <section class="card">
                    <div class="card-header"><h3 class="card-title">{{ $items->first()->store?->name }}</h3><span class="badge badge-gray">{{ $items->count() }}</span></div>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>الكود</th><th>النتيجة</th><th>سبب الإلغاء</th></tr></thead>
                        <tbody>
                        @foreach($items->sortBy(fn ($o) => $o->result->value) as $o)
                            <tr><td class="ltr text-right font-bold">{{ $o->code }}</td><td><x-badge :enum="$o->result"/></td><td>{{ $o->cancel_reason ?? '—' }}</td></tr>
                        @endforeach
                        </tbody>
                    </table></div>
                </section>
            @empty
                <x-empty-state title="لا توجد طلبيات بعد" icon="box">أضيفي الطلبيات من صفحة التعديل.</x-empty-state>
            @endforelse

            <section class="card p-5 text-sm space-y-1">
                <h2 class="card-title mb-2">أوقات المحاولات: {{ count($report->attempt_times ?? []) }}</h2>
                <p>{{ implode(' — ', $report->attempt_times ?? []) ?: 'لا يوجد' }}</p>
                <p><b>ملاحظة:</b> {{ $report->notes ?: 'لا يوجد' }}</p>
            </section>

            @includeWhen(View::exists('reports._review'), 'reports._review')
        </div>

        <aside class="space-y-5">
            <section class="card p-5 text-sm">
                <h2 class="card-title mb-2">نص واتساب</h2>
                <pre class="whitespace-pre-wrap font-sans leading-7 text-slate-700">{{ $whatsapp }}</pre>
            </section>
        </aside>
    </div>
</x-layouts.app>
