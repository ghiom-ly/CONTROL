@props(['code'])
@php $url = \App\Support\ShipmentUrl::for($code); @endphp
@if($url)
    <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" title="فتح في منظومة الشحن" {{ $attributes->merge(['class' => 'ltr inline-block text-navy-700 underline decoration-dotted hover:text-accent-600']) }}>{{ $code }}</a>
@else
    <span {{ $attributes->merge(['class' => 'ltr inline-block']) }}>{{ $code }}</span>
@endif
