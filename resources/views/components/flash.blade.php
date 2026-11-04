@php
    $messages = array_filter([
        'success' => session('success'),
        'warning' => session('warning'),
        'error' => session('error'),
    ]);
    $styles = [
        'success' => ['bg-emerald-50 border-emerald-200 text-emerald-800', 'check-circle'],
        'warning' => ['bg-amber-50 border-amber-200 text-amber-800', 'alert'],
        'error' => ['bg-red-50 border-red-200 text-red-800', 'alert'],
    ];
@endphp
<div class="space-y-3 mb-5">
    @foreach($messages as $type => $message)
        <div x-data="{ show: true }" x-show="show" x-transition
             class="flex items-start gap-3 rounded-xl border px-4 py-3 text-sm font-semibold {{ $styles[$type][0] }}">
            <x-icon :name="$styles[$type][1]" class="size-5 shrink-0"/>
            <div class="flex-1">{{ $message }}</div>
            <button type="button" x-on:click="show = false" class="opacity-60 hover:opacity-100" aria-label="إغلاق"><x-icon name="x" class="size-4"/></button>
        </div>
    @endforeach
    @if($errors->any() && ! session('success'))
        <div class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <x-icon name="alert" class="size-5 shrink-0"/>
            <div>
                <p class="font-bold">يرجى تصحيح الأخطاء التالية:</p>
                <ul class="mt-1 list-disc ps-5 space-y-0.5">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        </div>
    @endif
</div>
