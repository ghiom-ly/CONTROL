@props(['label', 'value', 'icon' => 'chart', 'tone' => 'navy', 'hint' => null, 'href' => null])
@php
    $tones = [
        'navy' => 'bg-navy-50 text-navy-700',
        'orange' => 'bg-brand-50 text-brand-600',
        'green' => 'bg-emerald-50 text-emerald-600',
        'yellow' => 'bg-amber-50 text-amber-600',
        'red' => 'bg-red-50 text-red-600',
        'blue' => 'bg-sky-50 text-sky-600',
        'gray' => 'bg-slate-100 text-slate-500',
    ];
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'card p-4 sm:p-5 flex items-start gap-4 transition hover:shadow-md hover:-translate-y-0.5']) }}>
    <div class="shrink-0 rounded-xl p-2.5 {{ $tones[$tone] ?? $tones['navy'] }}">
        <x-icon :name="$icon" class="size-6"/>
    </div>
    <div class="min-w-0">
        <p class="text-xs sm:text-sm font-semibold text-slate-500 leading-5">{{ $label }}</p>
        <p class="mt-1 text-2xl font-extrabold text-navy-900 tabular-nums">
            @if($value === null)
                <span class="text-base font-bold text-slate-400" title="لا توجد بيانات مُدخلة لهذه الفترة">غير متوفر</span>
            @else
                {{ is_numeric($value) ? number_format($value, is_float($value) ? 1 : 0) : $value }}
            @endif
        </p>
        @if($hint)<p class="mt-1 text-xs text-slate-400">{{ $hint }}</p>@endif
    </div>
</{{ $tag }}>
