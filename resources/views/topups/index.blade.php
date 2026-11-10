<x-layouts.app title="طلبات كروت التعبئة">
    <x-page-header title="طلبات كروت التعبئة" subtitle="اشترِ الكرت خارج المنظومة، ثم أدرج رقمه هنا ليظهر للموظف">
        <x-slot:actions>
            <a href="{{ route('topups.costs') }}" class="btn btn-light"><x-icon name="chart" class="size-4"/> التكاليف الشهرية</a>
        </x-slot:actions>
    </x-page-header>
    @if($errors->any())
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif

    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach(\App\Models\TopupRequest::STATUSES as $s => $label)
            <a href="{{ route('topups.index', ['status' => $s]) }}" class="btn btn-sm {{ $status === $s ? 'btn-primary' : 'btn-light' }}">{{ $label }} ({{ $counts[$s] ?? 0 }})</a>
        @endforeach
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table text-sm">
                <thead><tr><th>الموظف</th><th>الطلب</th><th>{{ $status === 'pending' ? 'القرار' : 'التفاصيل' }}</th></tr></thead>
                <tbody>
                @forelse($requests as $r)
                    <tr class="align-top">
                        <td class="font-bold whitespace-nowrap">{{ $r->user?->name }}
                            <p class="text-xs font-normal text-slate-400">قُدّم {{ $r->created_at->format('Y-m-d H:i') }}</p></td>
                        <td class="whitespace-nowrap">{{ $r->summary() }}
                            @if($r->note)<p class="text-xs text-slate-500">{{ $r->note }}</p>@endif</td>
                        <td class="min-w-72">
                            @if($r->status === 'pending')
                                <div class="flex flex-col gap-2">
                                    <form method="POST" action="{{ route('topups.approve', $r) }}" class="flex flex-wrap gap-1">
                                        @csrf @method('PATCH')
                                        <input name="card_number" inputmode="numeric" required class="input !py-1 !w-48 font-mono ltr" placeholder="رقم الكرت">
                                        <input name="decision_note" maxlength="300" class="input !py-1 !w-36 text-xs" placeholder="ملاحظة (اختياري)">
                                        <button class="btn btn-primary btn-sm">إدراج الكرت</button>
                                    </form>
                                    <form method="POST" action="{{ route('topups.reject', $r) }}" class="flex gap-1">
                                        @csrf @method('PATCH')
                                        <input name="decision_note" maxlength="300" class="input !py-1 !w-48 text-xs" placeholder="سبب الرفض (اختياري)">
                                        <button class="btn btn-light btn-sm text-red-700">رفض</button>
                                    </form>
                                </div>
                            @else
                                <x-badge :color="\App\Models\TopupRequest::STATUS_COLORS[$r->status]">{{ \App\Models\TopupRequest::STATUSES[$r->status] }}</x-badge>
                                @if(in_array($r->status, \App\Models\TopupRequest::COSTED, true))
                                    <span class="ms-2 inline-block font-mono ltr">{{ $r->card_number }}</span>
                                @endif
                                @if($r->decision_note)<p class="mt-1 text-xs">{{ $r->decision_note }}</p>@endif
                                @if($r->employee_note)<p class="mt-1 text-xs text-red-700">الموظف: {{ $r->employee_note }}</p>@endif
                                @if($r->decided_at)<p class="text-xs text-slate-400">{{ $r->decider?->name }} — {{ $r->decided_at->format('Y-m-d H:i') }}</p>@endif
                                @if($r->used_at)<p class="text-xs text-emerald-700">عُبّئ {{ $r->used_at->format('Y-m-d H:i') }}</p>@endif
                            @endif
                            <div class="mt-2">
                                <x-confirm-form :action="route('topups.destroy', $r)" method="DELETE" class="btn btn-ghost btn-sm text-red-600"
                                                message="حذف هذا الطلب نهائياً؟ يُستخدم للطلبات الخاطئة أو التجريبية، ويخرج من التكاليف ولا يمكن استعادته.">
                                    <x-icon name="trash" class="size-4"/> حذف
                                </x-confirm-form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3"><x-empty-state title="لا توجد طلبات" icon="phone"/></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $requests->links() }}
    </div>
</x-layouts.app>
