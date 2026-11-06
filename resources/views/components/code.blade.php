@props(['code'])
@php
    $url = \App\Support\ShipmentUrl::for($code);
    $internal = auth()->check() && auth()->user()->can('lookup', \App\Models\Shipment::class) && filled($code);
@endphp
@if($url)
    <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" title="فتح في منظومة الشحن" {{ $attributes->merge(['class' => 'ltr inline-block text-navy-700 underline decoration-dotted hover:text-accent-600']) }}>{{ $code }}</a>
@else
    <span {{ $attributes->merge(['class' => 'ltr inline-block']) }}>{{ $code }}</span>
@endif
@if($internal)<a href="{{ route('shipments.show', $code) }}" title="سجل الكود في المنظومة" class="text-xs text-slate-400 hover:text-navy-700">🔍</a>@endif
