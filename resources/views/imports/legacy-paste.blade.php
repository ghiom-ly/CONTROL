<x-layouts.app title="استيراد تقارير قديمة">
    <x-page-header title="استيراد تقارير قديمة من واتساب" subtitle="الصق نص تقرير أو عدة تقارير كما هي — كل تقرير يبدأ بسطر «ريبورت تاريخ:»"/>
    <form method="POST" action="{{ route('imports.legacy.preview') }}" class="card p-5 space-y-3">
        @csrf
        <textarea name="text" rows="16" required class="input font-sans leading-7 @error('text') input-error @enderror" placeholder="ريبورت تاريخ: ...">{{ old('text') }}</textarea>
        @error('text')<p class="field-error">{{ $message }}</p>@enderror
        <button class="btn btn-primary">قراءة ومعاينة</button>
    </form>
</x-layouts.app>
