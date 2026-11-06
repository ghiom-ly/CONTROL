<x-layouts.app title="ملخص التنبيهات لواتساب">
    <x-page-header title="ملخص التنبيهات لواتساب" subtitle="انسخ النص أو افتحه في واتساب واختر المحادثة أو مجموعة المشرفين">
        <x-slot:actions><a href="{{ route('alerts.index') }}" class="btn btn-light">رجوع للتنبيهات</a></x-slot:actions>
    </x-page-header>
    <div class="card max-w-3xl p-5" x-data="{ done: false }">
        <textarea x-ref="t" rows="16" class="input" readonly>{{ $text }}</textarea>
        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="btn btn-light" x-on:click="navigator.clipboard.writeText($refs.t.value); done = true; setTimeout(() => done = false, 2000)">
                <span x-text="done ? 'تم النسخ ✓' : 'نسخ النص'">نسخ النص</span>
            </button>
            <a href="{{ $waUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-accent"><x-icon name="phone" class="size-4"/> فتح في واتساب</a>
        </div>
    </div>
</x-layouts.app>
