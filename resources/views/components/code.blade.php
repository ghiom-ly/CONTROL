@props(['code'])
@php
    $fulfill = \App\Support\ShipmentUrl::fulfillment($code);
    $url = $fulfill ? null : \App\Support\ShipmentUrl::for($code);
    $internal = auth()->check() && auth()->user()->can('lookup', \App\Models\Shipment::class) && filled($code);
@endphp
@if($fulfill)
    {{-- منظومة التجهيز لا تقبل الكود في الرابط: ننسخ الكود ونفتح المنظومة، والموظف يلصقه في البحث --}}
    <span x-data="{ done: false }" class="relative inline-block">
        <button type="button" data-copy-open="{{ $fulfill }}" title="نسخ الكود وفتح منظومة التجهيز"
                x-on:click="navigator.clipboard?.writeText(@js($code)).catch(() => {}); window.open($el.dataset.copyOpen, '_blank', 'noopener'); done = true; setTimeout(() => done = false, 2500)"
                {{ $attributes->merge(['class' => 'ltr inline-block cursor-pointer text-navy-700 underline decoration-dotted hover:text-accent-600']) }}>{{ $code }}</button>
        <span x-show="done" x-cloak x-transition.opacity class="absolute -top-6 start-0 whitespace-nowrap rounded bg-slate-800 px-2 py-0.5 text-xs font-normal text-white">نُسخ — الصقه في البحث (Ctrl+V)</span>
    </span>
@elseif($url)
    <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" title="فتح في منظومة الشحن" {{ $attributes->merge(['class' => 'ltr inline-block text-navy-700 underline decoration-dotted hover:text-accent-600']) }}>{{ $code }}</a>
@else
    <span {{ $attributes->merge(['class' => 'ltr inline-block']) }}>{{ $code }}</span>
@endif
@if($internal)<a href="{{ route('shipments.show', $code) }}" title="سجل الكود في المنظومة" class="text-xs text-slate-400 hover:text-navy-700">🔍</a>@endif
