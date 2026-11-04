@props(['title' => 'لا توجد بيانات', 'icon' => 'box'])
<div class="flex flex-col items-center justify-center py-14 text-center">
    <div class="rounded-2xl bg-slate-100 p-4 text-slate-400"><x-icon :name="$icon" class="size-8"/></div>
    <p class="mt-4 font-bold text-slate-600">{{ $title }}</p>
    @if(trim($slot))<p class="mt-1 max-w-sm text-sm text-slate-400">{{ $slot }}</p>@endif
</div>
