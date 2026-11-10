<x-layouts.app title="كروت التعبئة">
    <x-page-header title="كروت التعبئة" subtitle="اطلب كرت ليبيانا أو المدار، وبعد موافقة المدير يظهر رقم الكرت هنا لتعبّيه"/>
    @if($errors->any())
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif

    <form method="POST" action="{{ route('my-topups.store') }}" class="card p-5 space-y-4 text-sm">
        @csrf
        <h3 class="card-title">طلب كرت جديد</h3>
        <div class="flex flex-wrap gap-6">
            <div>
                <p class="label">الشبكة</p>
                <div class="flex gap-2">
                    @foreach(\App\Models\TopupRequest::OPERATORS as $op => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-4 py-2 has-[:checked]:border-navy-700 has-[:checked]:bg-navy-50 has-[:checked]:font-bold">
                            <input type="radio" name="operator" value="{{ $op }}" @checked(old('operator', 'libyana') === $op) required> {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div>
                <p class="label">الفئة</p>
                <div class="flex gap-2">
                    @foreach(\App\Models\TopupRequest::AMOUNTS as $a)
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-4 py-2 has-[:checked]:border-navy-700 has-[:checked]:bg-navy-50 has-[:checked]:font-bold">
                            <input type="radio" name="amount" value="{{ $a }}" @checked((int) old('amount', 5) === $a) required> {{ $a }} دينار
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="grow"><label class="label">ملاحظة (اختياري)</label>
                <input name="note" value="{{ old('note') }}" maxlength="300" class="input" placeholder="مثلاً: رصيد للاتصال بالمندوبين"></div>
        </div>
        <div class="flex justify-end"><button class="btn btn-primary">إرسال الطلب</button></div>
    </form>

    <div class="card mt-5">
        <div class="table-wrap">
            <table class="table text-sm">
                <thead><tr><th>الطلب</th><th>الحالة</th><th>رقم الكرت</th><th></th></tr></thead>
                <tbody>
                @forelse($requests as $r)
                    <tr class="align-top">
                        <td class="font-semibold whitespace-nowrap">{{ $r->summary() }}
                            <p class="text-xs font-normal text-slate-400">قُدّم {{ $r->created_at->format('Y-m-d H:i') }}</p>
                            @if($r->note)<p class="text-xs font-normal text-slate-500">{{ $r->note }}</p>@endif
                        </td>
                        <td><x-badge :color="\App\Models\TopupRequest::STATUS_COLORS[$r->status]">{{ \App\Models\TopupRequest::STATUSES[$r->status] }}</x-badge>
                            @if($r->decision_note)<p class="mt-1 text-xs">{{ $r->decision_note }}</p>@endif
                            @if($r->employee_note)<p class="mt-1 text-xs text-red-700">{{ $r->employee_note }}</p>@endif
                        </td>
                        <td>
                            @if(in_array($r->status, \App\Models\TopupRequest::COSTED, true))
                                <span class="inline-block select-all rounded-lg bg-slate-100 px-3 py-1 font-mono text-base font-bold tracking-wider ltr">{{ $r->card_number }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-left whitespace-nowrap">
                            @if($r->status === 'pending')
                                <x-confirm-form :action="route('my-topups.cancel', $r)" method="PATCH" message="إلغاء هذا الطلب؟">إلغاء</x-confirm-form>
                            @elseif($r->status === 'issued')
                                <div class="flex flex-wrap items-start gap-2">
                                    <form method="POST" action="{{ route('my-topups.used', $r) }}">@csrf @method('PATCH')
                                        <button class="btn btn-primary btn-sm">تمت التعبئة</button></form>
                                    <form method="POST" action="{{ route('my-topups.faulty', $r) }}" class="flex gap-1">@csrf @method('PATCH')
                                        <input name="note" maxlength="300" class="input !py-1 !w-36 text-xs" placeholder="شن المشكلة؟">
                                        <button class="btn btn-light btn-sm text-red-700">الكرت لا يعمل</button></form>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4"><x-empty-state title="لا توجد طلبات بعد" icon="phone"/></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $requests->links() }}
    </div>
</x-layouts.app>
