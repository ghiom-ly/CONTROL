@props(['enum' => null, 'color' => 'gray'])
@php $c = $enum?->color() ?? $color; @endphp
<span {{ $attributes->merge(['class' => "badge badge-{$c}"]) }}>
    <span class="size-1.5 rounded-full bg-current opacity-70"></span>
    {{ $enum?->label() ?? $slot }}
</span>
