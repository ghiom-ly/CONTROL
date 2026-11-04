{{-- إرسال التقرير = نهاية الشفت (للموظف صاحب التقرير) --}}
@can('submit', $report)
    <section class="card p-5 mt-5 border-brand-200">
        <h2 class="card-title mb-2">إنهاء الشفت وإرسال التقرير</h2>
        <p class="hint mb-3">احفظ المسودة أولاً إن عدّلت شيئاً، ثم أدخل نسبة التسليم النهائية وأرسل.</p>
        <form method="POST" action="{{ route('reports.submit', $report) }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="label">نسبة التسليم النهائية % <span class="text-red-500">*</span></label>
                <input name="end_delivery_rate" inputmode="decimal" required value="{{ old('end_delivery_rate', $report->end_delivery_rate) }}" class="input !w-32 @error('end_delivery_rate') input-error @enderror">
            </div>
            <button class="btn btn-accent">إرسال للمراجعة</button>
        </form>
        @error('end_delivery_rate')<p class="field-error">{{ $message }}</p>@enderror
        @error('stores')<p class="field-error">{{ $message }}</p>@enderror
    </section>
@endcan
