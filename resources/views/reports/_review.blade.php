{{-- قرار المراجعة: اعتماد / تصحيح ثم اعتماد / إرجاع للموظف --}}
@if($report->status === \App\Enums\ReportStatus::PendingReview && $report->user_id !== auth()->id())
    @can('review', $report)
        <section class="card p-5 border-brand-200">
            <h2 class="card-title mb-3">قرار المراجعة</h2>
            <div class="flex flex-wrap items-start gap-2">
                <form method="POST" action="{{ route('reports.approve', $report) }}">@csrf
                    <button class="btn btn-accent"><x-icon name="check-circle" class="size-4"/> اعتماد</button>
                </form>
                <a href="{{ route('reports.edit', ['report' => $report, 'approve_after' => 1]) }}" class="btn btn-light"><x-icon name="pencil" class="size-4"/> تصحيح ثم اعتماد</a>
                <button type="button" class="btn btn-danger" x-on:click="$dispatch('open-modal', 'reject-report')">إرجاع للموظف</button>
            </div>
            @error('codes')<p class="field-error mt-2">{{ $message }}</p>@enderror
            @error('status')<p class="field-error mt-2">{{ $message }}</p>@enderror
        </section>

        <x-modal name="reject-report" title="إرجاع التقرير للموظف" :show="$errors->has('rejection_reason')">
            <form method="POST" action="{{ route('reports.reject', $report) }}" class="space-y-3">
                @csrf
                <label class="label">سبب الإرجاع <span class="text-red-500">*</span></label>
                <textarea name="rejection_reason" rows="3" required maxlength="1000" class="input @error('rejection_reason') input-error @enderror">{{ old('rejection_reason') }}</textarea>
                @error('rejection_reason')<p class="field-error">{{ $message }}</p>@enderror
                <div class="flex justify-end"><button class="btn btn-danger">إرجاع</button></div>
            </form>
        </x-modal>
    @endcan
@endif
