@props(['action', 'method' => 'POST', 'message' => 'هل أنت متأكد؟', 'class' => 'btn btn-light btn-sm'])
{{-- نموذج بتأكيد داخل الصفحة (بدون نوافذ المتصفح الافتراضية) --}}
<div x-data="{ ask: false }" class="inline-block">
    <button type="button" class="{{ $class }}" x-on:click="ask = true">{{ $slot }}</button>
    <div x-show="ask" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-navy-950/60" x-on:click="ask = false"></div>
        <div class="relative w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl text-right">
            <div class="flex items-start gap-3">
                <div class="rounded-xl bg-amber-50 p-2 text-amber-600"><x-icon name="alert"/></div>
                <p class="font-semibold text-slate-700 leading-7">{{ $message }}</p>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="btn btn-light" x-on:click="ask = false">إلغاء</button>
                <form method="POST" action="{{ $action }}">
                    @csrf
                    @if(strtoupper($method) !== 'POST') @method($method) @endif
                    <button type="submit" class="btn btn-primary">تأكيد</button>
                </form>
            </div>
        </div>
    </div>
</div>
