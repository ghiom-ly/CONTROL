@php $money = fn ($v) => \App\Models\PayrollItem::money($v); $locked = $run?->isApproved(); @endphp
<x-layouts.app title="الرواتب">
    <x-page-header title="الرواتب" :subtitle="'شهر '.$month->format('Y-m').' — قيمة اليوم = الراتب ÷ 30، والخصم من الغياب والشفتات غير المكتملة'">
        <x-slot:actions>
            @include('attendance._month-filter')
            @if($run)
                <a href="{{ route('payroll.export', ['month' => $month->format('Y-m')]) }}" class="btn btn-light">تصدير Excel</a>
            @endif
        </x-slot:actions>
    </x-page-header>
    @if($errors->any())
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif

    <div class="card mb-5 flex flex-wrap items-center gap-3 p-5 text-sm">
        @if(! $run)
            <span class="badge badge-gray">لم يُحسب بعد</span>
        @elseif($locked)
            <span class="badge badge-green">معتمد ومقفل</span>
            <span class="text-slate-500">{{ $run->approver?->name }} — {{ $run->approved_at?->format('Y-m-d H:i') }}</span>
        @else
            <span class="badge badge-yellow">مسودة</span>
            <span class="text-slate-500">آخر حساب {{ $run->computed_at?->format('Y-m-d H:i') }}</span>
        @endif
        <div class="ms-auto flex flex-wrap gap-2">
            @if(! $locked)
                <form method="POST" action="{{ route('payroll.compute') }}">@csrf <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                    <button class="btn btn-primary">{{ $run ? 'إعادة الحساب' : 'احسب الكشف' }}</button></form>
                @if($run && $canApprove)
                    <x-confirm-form :action="route('payroll.approve').'?month='.$month->format('Y-m')" class="btn btn-accent"
                                    message="اعتماد كشف {{ $month->format('Y-m') }}؟ تُحفظ الأرقام كما هي ولا تتغير بتعديل الحضور لاحقاً.">اعتماد الشهر</x-confirm-form>
                @elseif($run)
                    <span class="hint self-center">الاعتماد بعد انتهاء الشهر</span>
                @endif
            @else
                <x-confirm-form :action="route('payroll.reopen').'?month='.$month->format('Y-m')" class="btn btn-light"
                                message="إعادة فتح الكشف للتعديل؟ تُسجَّل في سجل العمليات.">إعادة فتح الشهر</x-confirm-form>
            @endif
        </div>
    </div>

    @if($missing->isNotEmpty())
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <b>لم يُحدَّد راتب</b> (لا يدخلون الكشف — حدّده من «تعديل الموظف»):
            @foreach($missing as $u)<a href="{{ route('users.show', $u) }}" class="underline">{{ $u->name }}</a>@if(! $loop->last)، @endif @endforeach
        </div>
    @endif

    @if($run)
        <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <x-stat-card label="إجمالي الصافي" :value="$money($items->sum('net')).' د.ل'" icon="chart" tone="navy"/>
            <x-stat-card label="خصم الغياب" :value="$money($items->sum('absence_deduction')).' د.ل'" icon="user-x" tone="red"/>
            <x-stat-card label="خصم الشفتات غير المكتملة" :value="$money($items->sum('incomplete_deduction')).' د.ل'" icon="alert" tone="orange"/>
            <x-stat-card label="عدد الموظفين" :value="$items->count()" icon="users" tone="green"/>
        </div>

        <div class="card">
            <div class="table-wrap">
                <table class="table text-sm">
                    <thead><tr><th>الموظف</th><th>الأساسي</th><th>الغياب</th><th>شفت غير مكتمل</th><th>التأخير (عرض)</th><th>التقارير (عرض)</th><th>إضافات / خصومات</th><th>الصافي</th><th></th></tr></thead>
                    <tbody>
                    @forelse($items as $i)
                        <tr class="align-top">
                            <td class="font-bold whitespace-nowrap">{{ $i->user?->name }}</td>
                            <td class="tabular-nums whitespace-nowrap">{{ $money($i->base_salary) }}
                                @if($i->paid_days < \App\Services\PayrollService::DAYS)<p class="text-xs text-slate-500">{{ $i->paid_days }} يوماً ⇐ {{ $money($i->base_due) }}</p>@endif</td>
                            <td class="tabular-nums">@if($i->absent_days)<span class="text-red-700">−{{ $money($i->absence_deduction) }}</span>
                                <p class="text-xs text-slate-500">{{ $i->absent_days }} يوم: {{ implode('، ', $i->absent_dates ?? []) }}</p>@else — @endif</td>
                            <td class="tabular-nums">@if($i->incomplete_days)<span class="text-red-700">−{{ $money($i->incomplete_deduction) }}</span>
                                <p class="text-xs text-slate-500">{{ $i->incomplete_days }} يوم ({{ $i->incomplete_percent }}%): {{ implode('، ', $i->incomplete_dates ?? []) }}</p>@else — @endif</td>
                            <td class="tabular-nums text-slate-600">{{ $i->late_count ? $i->late_count.' مرة — '.$i->late_minutes.' د' : '—' }}</td>
                            <td class="tabular-nums text-slate-600">{{ $i->reports_approved }} / {{ $i->reports_total }}</td>
                            <td class="min-w-56 text-xs">
                                @foreach($adjustments[$i->user_id] ?? [] as $a)
                                    <div class="flex items-center gap-1">
                                        <span class="{{ $a->kind === 'addition' ? 'text-emerald-700' : 'text-red-700' }}">{{ $a->kind === 'addition' ? '+' : '−' }}{{ $money($a->amount) }}</span> {{ $a->label }}
                                        @unless($locked)
                                            <form method="POST" action="{{ route('payroll.adjustments.destroy', $a) }}" class="inline">@csrf @method('DELETE')
                                                <button class="text-slate-400 hover:text-red-600" title="حذف">✕</button></form>
                                        @endunless
                                    </div>
                                @endforeach
                                @unless($locked)
                                    <details class="mt-1"><summary class="cursor-pointer text-navy-700">+ إضافة / خصم</summary>
                                        <form method="POST" action="{{ route('payroll.adjustments.store') }}" class="mt-1 flex flex-wrap gap-1">@csrf
                                            <input type="hidden" name="month" value="{{ $month->format('Y-m') }}"><input type="hidden" name="user_id" value="{{ $i->user_id }}">
                                            <select name="kind" class="input !w-auto !py-1 text-xs">@foreach(\App\Models\PayrollAdjustment::KINDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                                            <input name="label" required maxlength="150" class="input !w-28 !py-1 text-xs" placeholder="البند (مكافأة، سلفة…)">
                                            <input name="amount" required inputmode="decimal" class="input !w-20 !py-1 text-xs" placeholder="المبلغ">
                                            <button class="btn btn-light btn-sm">حفظ</button>
                                        </form>
                                    </details>
                                @endunless
                            </td>
                            <td class="font-extrabold tabular-nums whitespace-nowrap text-navy-900">{{ $money($i->net) }} د.ل</td>
                            <td><a href="{{ route('payroll.slip', $i) }}" class="btn btn-light btn-sm">القسيمة</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><x-empty-state title="لا يوجد موظفون برواتب محددة" icon="users"/></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-layouts.app>
