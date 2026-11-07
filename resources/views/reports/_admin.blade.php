{{-- إلغاء شفت بُدئ بالخطأ: للمسؤول والمدير العام، مسودة لم تُرسل فقط --}}
@if($report->status === \App\Enums\ReportStatus::Draft && $report->first_submitted_at === null
    && auth()->user()->can('attendance.manage') && $report->user_id !== auth()->id())
    <section class="card p-5 border-red-200">
        <h2 class="card-title mb-1">إلغاء الشفت</h2>
        <p class="hint mb-3">إذا بدأ الموظف الشفت بالخطأ: يُلغى حضوره لهذا اليوم ويستطيع بدء شفت جديد. يُسجَّل في سجل العمليات.</p>
        <x-confirm-form :action="route('reports.cancel-shift', $report)" method="DELETE" class="btn btn-sm bg-red-50 text-red-700 hover:bg-red-100"
                        message="إلغاء شفت {{ $report->user?->name }} ({{ $report->report_number }})؟">
            <x-icon name="x" class="size-4"/> إلغاء الشفت
        </x-confirm-form>
        @error('shift')<p class="field-error mt-2">{{ $message }}</p>@enderror
    </section>
@endif
{{-- أدوات المدير العام على التقرير: وقت الشفت والحذف --}}
@if(auth()->user()->isSuperAdmin())
    <section class="card p-5 border-amber-200">
        <h2 class="card-title mb-3">أدوات المدير العام</h2>
        <form method="POST" action="{{ route('reports.shift-time', $report) }}" class="flex flex-wrap items-end gap-2 text-sm">
            @csrf @method('PUT')
            <div><label class="label">بدء الشفت (الحضور)</label>
                <input type="datetime-local" name="shift_started_at" required class="input" value="{{ old('shift_started_at', $report->shift_started_at?->format('Y-m-d\TH:i')) }}"></div>
            <div><label class="label">الانصراف (أول إرسال)</label>
                <input type="datetime-local" name="first_submitted_at" class="input" value="{{ old('first_submitted_at', $report->first_submitted_at?->format('Y-m-d\TH:i')) }}"></div>
            <button class="btn btn-light">حفظ وقت الشفت</button>
            @error('shift_started_at')<p class="field-error w-full">{{ $message }}</p>@enderror
            @error('first_submitted_at')<p class="field-error w-full">{{ $message }}</p>@enderror
        </form>
        <p class="hint mt-1">تغيير تاريخ البدء ينقل التقرير لليوم الجديد: يُحسب الحضور فيه، ويُعاد حساب اليوم القديم.</p>
        <div class="mt-4 border-t border-slate-100 pt-3">
            <x-confirm-form :action="route('reports.destroy', $report)" method="DELETE" class="btn btn-sm bg-red-50 text-red-700 hover:bg-red-100"
                            message="حذف التقرير {{ $report->report_number }}؟ تُزال أرقامه من التحليلات والحضور، ويمكن استعادته من «المحذوفة».">
                <x-icon name="trash" class="size-4"/> حذف التقرير
            </x-confirm-form>
        </div>
    </section>
@endif
