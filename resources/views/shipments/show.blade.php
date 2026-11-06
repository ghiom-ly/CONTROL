<x-layouts.app :title="'الكود '.$code">
    <x-page-header :title="'الكود '.$code" subtitle="ملخص الشحنة ومتابعاتها">
        <x-slot:actions>
            @if($url = \App\Support\ShipmentUrl::for($code))
                <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="btn btn-light"><x-icon name="eye" class="size-4"/> فتح في منظومة الشحن</a>
            @endif
            <a href="{{ route('shipments.index') }}" class="btn btn-light"><x-icon name="search" class="size-4"/> بحث عن كود آخر</a>
        </x-slot:actions>
    </x-page-header>

    @if($confirmations->isNotEmpty())
        <section class="card mb-5">
            <div class="card-header"><h2 class="card-title">سجل التأكيد</h2><span class="badge badge-gray">{{ $confirmations->count() }}</span></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>التاريخ</th><th>المتجر</th><th>النتيجة</th><th>سبب الإلغاء</th>@if($full)<th>الموظفة</th><th>التقرير</th>@endif</tr></thead>
                <tbody>
                @foreach($confirmations as $c)
                    <tr>
                        <td class="tabular-nums">{{ $c->report?->work_date?->format('Y/m/d') }}</td>
                        <td>{{ $c->store?->name }}</td>
                        <td><x-badge :enum="$c->result"/></td>
                        <td>{{ $c->cancel_reason ?? '—' }}</td>
                        @if($full)
                            <td>{{ $c->user?->name }}</td>
                            <td>@if($c->report)<a class="text-navy-700 underline" href="{{ route('reports.show', $c->report) }}">{{ $c->report->report_number }}</a> <x-badge :enum="$c->report->status"/>@endif</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </section>
    @endif

    @if(! $shipment && $confirmations->isEmpty())
        <x-empty-state title="لا يوجد كود بهذا الرقم" icon="search">لم يُسجَّل هذا الكود في أي تقرير بعد. يمكنك فتحه في منظومة الشحن من الزر أعلاه.</x-empty-state>
    @elseif($shipment)
        <section class="card mb-5 grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3 text-sm">
            <div><p class="text-slate-500">الكود</p><p class="font-bold text-lg"><x-code :code="$shipment->code"/></p></div>
            <div><p class="text-slate-500">المتجر</p><p class="font-bold">{{ $shipment->store?->name ?? '—' }}</p></div>
            <div><p class="text-slate-500">حالة المعالجة</p><x-badge :enum="$shipment->processing_status"/></div>
            <div><p class="text-slate-500">آخر نتيجة</p>@if($shipment->last_result)<x-badge :enum="$shipment->last_result"/>@else — @endif</div>
            <div><p class="text-slate-500">عدد المتابعات</p><p class="font-bold tabular-nums">{{ $shipment->followups_count }}</p></div>
            <div><p class="text-slate-500">آخر متابعة</p><p>{{ $shipment->lastFollowupUser?->name ?? '—' }} — <span class="tabular-nums">{{ $shipment->last_followup_at?->format('Y/m/d H:i') ?? '—' }}</span></p></div>
        </section>

        @if($full)
            <section class="card mb-5">
                <div class="card-header"><h2 class="card-title">سجل المتابعات الكامل</h2><span class="badge badge-gray">{{ $followups->count() }}</span></div>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>الوقت</th><th>الموظف</th><th>النتيجة</th><th>التقرير</th><th>رسمية</th></tr></thead>
                    <tbody>
                    @forelse($followups as $f)
                        <tr>
                            <td class="tabular-nums">{{ $f->followup_at?->format('Y/m/d H:i') }}</td>
                            <td>{{ $f->user?->name }}</td>
                            <td><x-badge :enum="$f->result"/></td>
                            <td>@if($f->report)<a class="text-navy-700 underline" href="{{ route('reports.show', $f->report) }}">{{ $f->report->report_number }}</a> <x-badge :enum="$f->report->status"/>@else — @endif</td>
                            <td>{{ $f->is_official ? 'نعم' : 'لا (بانتظار الاعتماد)' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-slate-500">لا توجد متابعات.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </section>
        @endif

        @isset($contacts)
            <section class="card mb-5" id="contacts">
                <div class="card-header"><h2 class="card-title">{{ $full ? 'الاتصالات المسجلة' : 'اتصالاتي على هذا الكود' }}</h2><span class="badge badge-gray">{{ $contacts->count() }}</span></div>
                @if(auth()->user()->hasPermission('contacts.create'))
                    <form method="POST" action="{{ route('shipments.contacts.store', $shipment->code) }}" class="flex flex-wrap items-end gap-2 border-b border-slate-100 p-4">
                        @csrf
                        <div><label class="label">تسجيل اتصال مع</label>
                            <select name="party_type" class="input !w-36" required>
                                <option value="customer">الزبون</option><option value="courier">المندوب</option>
                            </select></div>
                        <div><label class="label">النتيجة</label>
                            <select name="result" class="input !w-48" required>
                                @foreach(\App\Enums\ContactResult::cases() as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach
                            </select></div>
                        <div class="flex-1 min-w-48"><label class="label">ملاحظة (اختياري)</label><input name="notes" class="input" maxlength="1000"></div>
                        <button class="btn btn-accent">تسجيل اتصال</button>
                        @error('party_type')<p class="field-error w-full">{{ $message }}</p>@enderror
                    </form>
                @endif
                <div class="divide-y divide-slate-100 text-sm">
                    @forelse($contacts as $c)
                        <div class="px-5 py-2 flex flex-wrap gap-2 items-center">
                            <span class="tabular-nums text-slate-500">{{ $c->contacted_at?->format('Y/m/d H:i') }}</span>
                            <x-badge :enum="$c->party_type"/> <x-badge :enum="$c->result"/>
                            <span class="text-xs text-slate-500">محاولة {{ $c->attempt_number }}</span>
                            @if($full)<span>— {{ $c->user?->name }}</span>@endif
                            @if($c->notes)<span class="text-slate-600">— {{ $c->notes }}</span>@endif
                        </div>
                    @empty
                        <p class="px-5 py-3 text-slate-500">لا توجد اتصالات مسجلة.</p>
                    @endforelse
                </div>
            </section>
        @endisset

        @if($full)
            <div class="grid gap-5 lg:grid-cols-2">
                <section class="card">
                    <div class="card-header"><h2 class="card-title">المشكلات المرتبطة</h2></div>
                    <div class="divide-y divide-slate-100 text-sm">
                        @forelse($issues as $i)
                            <div class="px-5 py-2">{{ $i->issue_number }} — {{ $i->title }} <x-badge :enum="$i->status"/></div>
                        @empty
                            <p class="px-5 py-3 text-slate-500">لا توجد مشكلات.</p>
                        @endforelse
                    </div>
                </section>
                <section class="card">
                    <div class="card-header"><h2 class="card-title">رسائل المتجر التي ذكرت الكود</h2></div>
                    <div class="divide-y divide-slate-100 text-sm">
                        @forelse($messages as $m)
                            <div class="px-5 py-2"><span class="tabular-nums text-slate-500">{{ $m->created_at->format('Y/m/d H:i') }}</span> — {{ $m->sender?->name }}</div>
                        @empty
                            <p class="px-5 py-3 text-slate-500">لا توجد رسائل.</p>
                        @endforelse
                    </div>
                </section>
            </div>
        @endif
    @endif
</x-layouts.app>
