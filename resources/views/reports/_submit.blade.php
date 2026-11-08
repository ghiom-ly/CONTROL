{{-- إرسال التقرير للمراجعة من صفحة العرض: لصاحب التقرير فقط (المدير العام لا يرسل مسودة غيره) --}}
@if($report->user_id === auth()->id() && auth()->user()->can('submit', $report))
    @php $needsRate = ! $report->is_legacy && ! $report->isConfirmation(); @endphp
    <section class="card mb-5 border-brand-200 p-5">
        <form method="POST" action="{{ route('reports.submit', $report) }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="me-auto">
                <h2 class="card-title">إنهاء الشفت وإرسال التقرير</h2>
                <p class="hint">بعد الإرسال يُسجَّل انصرافك ويصل التقرير للإدارة، ولا يمكن تعديله إلا إذا أُرجع لك.</p>
            </div>
            @if($needsRate)
                <div>
                    <label class="label">نسبة التسليم النهائية %</label>
                    <input name="end_delivery_rate" inputmode="decimal" required value="{{ old('end_delivery_rate', $report->end_delivery_rate) }}" class="input !w-32 @error('end_delivery_rate') input-error @enderror">
                </div>
            @endif
            <button class="btn btn-accent">إرسال للمراجعة</button>
        </form>
        @foreach($errors->all() as $message)<p class="field-error mt-2">{{ $message }}</p>@endforeach
    </section>
@endif
