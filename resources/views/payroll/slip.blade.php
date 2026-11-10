@php $money = fn ($v) => \App\Models\PayrollItem::money($v).' د.ل'; $i = $item; @endphp
<x-layouts.app :title="'قسيمة راتب '.$i->user?->name">
    <x-page-header :title="'قسيمة راتب — '.$i->user?->name" :subtitle="'شهر '.$i->payrollMonth->month->format('Y-m').' — '.($i->payrollMonth->isApproved() ? 'معتمد' : 'مسودة')">
        <x-slot:actions>
            <button type="button" class="btn btn-light" onclick="window.print()">طباعة</button>
            @if($whatsapp)
                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn btn-accent"><x-icon name="phone" class="size-4"/> إرسال للموظف عبر واتساب</a>
            @endif
            <a href="{{ route('payroll.index', ['month' => $i->payrollMonth->month->format('Y-m')]) }}" class="btn btn-light">الكشف</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card mx-auto max-w-2xl p-6 text-sm">
        <h2 class="mb-1 text-lg font-extrabold text-navy-900">قسيمة راتب — {{ \App\Models\SystemSetting::get('company_name', 'شركة النورس') }}</h2>
        <p class="mb-4 text-slate-500">{{ $i->user?->name }} — شهر {{ $i->payrollMonth->month->format('Y-m') }}</p>
        <dl class="divide-y divide-slate-100">
            <div class="flex justify-between py-2"><dt>الراتب الأساسي</dt><dd class="tabular-nums">{{ $money($i->base_salary) }}</dd></div>
            <div class="flex justify-between py-2"><dt>قيمة اليوم (÷30)</dt><dd class="tabular-nums">{{ $money($i->day_value) }}</dd></div>
            @if($i->paid_days < \App\Services\PayrollService::DAYS)
                <div class="flex justify-between py-2"><dt>المستحق عن {{ $i->paid_days }} يوماً (من تاريخ التعيين)</dt><dd class="tabular-nums">{{ $money($i->base_due) }}</dd></div>
            @endif
            <div class="flex justify-between gap-3 py-2"><dt>خصم الغياب — {{ $i->absent_days }} يوم
                    @if($i->absent_days)<span class="block text-xs text-slate-500">{{ implode('، ', $i->absent_dates ?? []) }}</span>@endif</dt>
                <dd class="tabular-nums text-red-700">−{{ $money($i->absence_deduction) }}</dd></div>
            <div class="flex justify-between gap-3 py-2"><dt>خصم الشفت غير المكتمل — {{ $i->incomplete_days }} يوم ({{ $i->incomplete_percent }}%)
                    @if($i->incomplete_days)<span class="block text-xs text-slate-500">{{ implode('، ', $i->incomplete_dates ?? []) }}</span>@endif</dt>
                <dd class="tabular-nums text-red-700">−{{ $money($i->incomplete_deduction) }}</dd></div>
            @foreach($adjustments as $a)
                <div class="flex justify-between py-2"><dt>{{ \App\Models\PayrollAdjustment::KINDS[$a->kind] }}: {{ $a->label }}</dt>
                    <dd class="tabular-nums {{ $a->kind === 'addition' ? 'text-emerald-700' : 'text-red-700' }}">{{ $a->kind === 'addition' ? '+' : '−' }}{{ $money($a->amount) }}</dd></div>
            @endforeach
            <div class="flex justify-between py-3 text-base font-extrabold text-navy-900"><dt>الصافي</dt><dd class="tabular-nums">{{ $money($i->net) }}</dd></div>
        </dl>
        <p class="mt-4 text-xs text-slate-500">للعلم: التأخير {{ $i->late_count }} مرة ({{ $i->late_minutes }} دقيقة) — التقارير المعتمدة {{ $i->reports_approved }} من {{ $i->reports_total }}. لا خصم على التأخير.</p>
    </div>
</x-layouts.app>
