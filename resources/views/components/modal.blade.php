@props(['name', 'title', 'maxWidth' => 'max-w-2xl', 'show' => false])
<div x-data="{ open: @js((bool) $show) }"
     x-on:open-modal.window="if ($event.detail === '{{ $name }}') open = true"
     x-on:close-modal.window="if ($event.detail === '{{ $name }}') open = false"
     x-on:keydown.escape.window="open = false"
     x-show="open" x-cloak class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div x-show="open" x-transition.opacity class="fixed inset-0 bg-navy-950/60 backdrop-blur-sm" x-on:click="open = false"></div>
    <div class="relative flex min-h-full items-end sm:items-center justify-center p-0 sm:p-6">
        <div x-show="open" x-transition class="relative w-full {{ $maxWidth }} bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl">
            <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
                <h3 class="text-lg font-bold text-navy-900">{{ $title }}</h3>
                <button type="button" class="btn-ghost btn btn-sm" x-on:click="open = false" aria-label="إغلاق"><x-icon name="x"/></button>
            </div>
            <div class="p-5">{{ $slot }}</div>
        </div>
    </div>
</div>
