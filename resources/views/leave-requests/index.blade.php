<x-layouts.app title="طلبات الإجازة والأعذار">
    <x-page-header title="طلبات الإجازة والأعذار" subtitle="القبول يحدّث الحضور تلقائياً: الإجازة لا تُحسب غياباً، عذر الغياب = «غياب بعذر»، وعذر التأخير يلغي التأخير"/>
    @if($errors->any())
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif

    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach(\App\Models\LeaveRequest::STATUSES as $s => $label)
            <a href="{{ route('leave-requests.index', ['status' => $s]) }}" class="btn btn-sm {{ $status === $s ? 'btn-primary' : 'btn-light' }}">{{ $label }} ({{ $counts[$s] ?? 0 }})</a>
        @endforeach
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table text-sm">
                <thead><tr><th>الموظف</th><th>الطلب</th><th>السبب</th><th>{{ $status === 'pending' ? 'القرار' : 'الرد' }}</th></tr></thead>
                <tbody>
                @forelse($requests as $r)
                    <tr class="align-top">
                        <td class="font-bold whitespace-nowrap">{{ $r->user?->name }}
                            <p class="text-xs font-normal text-slate-400">قُدّم {{ $r->created_at->format('Y-m-d H:i') }}</p>
                            <a href="{{ route('attendance.show', $r->user_id) }}" class="text-xs font-normal text-navy-700 underline">سجل الحضور</a>
                        </td>
                        <td class="whitespace-nowrap">{{ $r->summary() }}</td>
                        <td class="max-w-sm">{{ $r->reason }}
                            @if($r->attachment_path)<a href="{{ route('leave-requests.attachment', $r) }}" target="_blank" class="block text-xs text-navy-700 underline">📎 {{ $r->attachment_name }}</a>@endif
                        </td>
                        <td class="min-w-64">
                            @if($r->isPending())
                                <div class="flex flex-col gap-2">
                                    <form method="POST" action="{{ route('leave-requests.approve', $r) }}" class="flex gap-1">
                                        @csrf @method('PATCH')
                                        <input name="note" class="input !py-1 text-xs" placeholder="ملاحظة (اختياري)" maxlength="500">
                                        <button class="btn btn-primary btn-sm">قبول</button>
                                    </form>
                                    <form method="POST" action="{{ route('leave-requests.reject', $r) }}" class="flex gap-1">
                                        @csrf @method('PATCH')
                                        <input name="note" class="input !py-1 text-xs" placeholder="سبب الرفض" required maxlength="500">
                                        <button class="btn btn-light btn-sm text-red-700">رفض</button>
                                    </form>
                                </div>
                            @else
                                <x-badge :color="\App\Models\LeaveRequest::STATUS_COLORS[$r->status]">{{ \App\Models\LeaveRequest::STATUSES[$r->status] }}</x-badge>
                                <p class="mt-1 text-xs">{{ $r->decision_note }}</p>
                                @if($r->decided_at)<p class="text-xs text-slate-400">{{ $r->decider?->name }} — {{ $r->decided_at->format('Y-m-d H:i') }}</p>@endif
                                @php $wa = \App\Http\Controllers\LeaveRequestController::decisionWhatsApp($r); @endphp
                                @if($wa)
                                    <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn btn-accent btn-sm mt-1"><x-icon name="phone" class="size-4"/> إبلاغ الموظف عبر واتساب</a>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4"><x-empty-state title="لا توجد طلبات" icon="calendar"/></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $requests->links() }}
    </div>
</x-layouts.app>
