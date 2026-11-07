<x-layouts.app title="طلباتي">
    <x-page-header title="طلباتي — إجازة أو عذر" subtitle="قدّم طلبك هنا، والمدير يوافق عليه أو يرفضه، ويصلك إشعار بالقرار"/>
    @if($errors->has('request'))
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first('request') }}</div>
    @endif

    <form method="POST" action="{{ route('my-requests.store') }}" enctype="multipart/form-data" class="card p-5 space-y-4 text-sm"
          x-data="{ kind: @js(old('kind', 'leave')) }">
        @csrf
        <h3 class="card-title">طلب جديد</h3>
        <div class="flex flex-wrap gap-2">
            @foreach(\App\Models\LeaveRequest::KINDS as $k => $label)
                <label class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2" :class="kind === '{{ $k }}' ? 'border-navy-700 bg-navy-50 font-bold' : 'border-slate-200'">
                    <input type="radio" name="kind" value="{{ $k }}" x-model="kind"> {{ $label }}
                </label>
            @endforeach
        </div>
        <p class="hint" x-show="kind === 'leave'">إجازة لأيام قادمة (من اليوم فصاعداً، حتى {{ \App\Http\Controllers\LeaveRequestController::MAX_LEAVE_DAYS }} يوماً).</p>
        <p class="hint" x-show="kind === 'absence_excuse'" x-cloak>عذر عن غياب يوم مضى (آخر {{ \App\Http\Controllers\LeaveRequestController::EXCUSE_DAYS_BACK }} يوماً) — إن قُبل يُحسب «غياب بعذر».</p>
        <p class="hint" x-show="kind === 'late_excuse'" x-cloak>عذر عن تأخير في يوم معيّن — إن قُبل لا يُحسب عليك التأخير.</p>

        <div class="grid gap-3 md:grid-cols-3">
            <div x-show="kind === 'leave'"><label class="label">نوع الإجازة</label>
                <select name="leave_type" class="input" :disabled="kind !== 'leave'">
                    @foreach(\App\Models\Leave::TYPES as $t => $tl)<option value="{{ $t }}" @selected(old('leave_type') === $t)>{{ $tl }}</option>@endforeach
                </select>
                @error('leave_type')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div><label class="label" x-text="kind === 'leave' ? 'من تاريخ' : 'اليوم'">من تاريخ</label>
                <input type="date" name="from_date" value="{{ old('from_date') }}" class="input" required>
                @error('from_date')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div x-show="kind === 'leave'"><label class="label">إلى تاريخ</label>
                <input type="date" name="to_date" value="{{ old('to_date') }}" class="input" :disabled="kind !== 'leave'" :required="kind === 'leave'">
                @error('to_date')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>
        <div><label class="label">السبب</label>
            <textarea name="reason" rows="2" class="input" required maxlength="1000">{{ old('reason') }}</textarea>
            @error('reason')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div><label class="label">مرفق (اختياري) — صورة أو PDF حتى 5 ميغا، مثل شهادة طبية</label>
            <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf" class="input">
            @error('attachment')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div class="flex justify-end"><button class="btn btn-primary">إرسال الطلب</button></div>
    </form>

    <div class="card mt-5">
        <div class="table-wrap">
            <table class="table text-sm">
                <thead><tr><th>الطلب</th><th>السبب</th><th>الحالة</th><th>الرد</th><th></th></tr></thead>
                <tbody>
                @forelse($requests as $r)
                    <tr>
                        <td class="font-semibold whitespace-nowrap">{{ $r->summary() }}<p class="text-xs text-slate-400">قُدّم {{ $r->created_at->format('Y-m-d H:i') }}</p></td>
                        <td class="max-w-xs">{{ $r->reason }}
                            @if($r->attachment_path)<a href="{{ route('leave-requests.attachment', $r) }}" target="_blank" class="block text-xs text-navy-700 underline">📎 {{ $r->attachment_name }}</a>@endif
                        </td>
                        <td><x-badge :color="\App\Models\LeaveRequest::STATUS_COLORS[$r->status]">{{ \App\Models\LeaveRequest::STATUSES[$r->status] }}</x-badge></td>
                        <td class="text-xs">{{ $r->decision_note ?: '—' }}
                            @if($r->decided_at)<p class="text-slate-400">{{ $r->decider?->name }} — {{ $r->decided_at->format('Y-m-d') }}</p>@endif
                        </td>
                        <td class="text-left whitespace-nowrap">
                            @if($r->isPending())
                                @php $wa = \App\Http\Controllers\LeaveRequestController::managerWhatsApp($r, $managerPhone); @endphp
                                @if($wa)
                                    <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn btn-accent btn-sm"><x-icon name="phone" class="size-4"/> تنبيه المدير عبر واتساب</a>
                                @endif
                                <form method="POST" action="{{ route('my-requests.cancel', $r) }}" class="inline" onsubmit="return confirm('إلغاء هذا الطلب؟')">
                                    @csrf @method('PATCH')
                                    <button class="btn btn-light btn-sm">إلغاء</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty-state title="لا توجد طلبات بعد" icon="calendar"/></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $requests->links() }}
    </div>
</x-layouts.app>
