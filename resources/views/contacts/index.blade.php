<x-layouts.app title="سجل الاتصالات">
    <x-page-header title="سجل الاتصالات" :subtitle="'الفترة: '.$from->format('Y/m/d').' — '.$to->format('Y/m/d').' (المتابعات الرسمية من التقارير + الاتصالات المسجلة يدوياً)'"/>

    <form method="GET" class="card mb-5 flex flex-col gap-3 p-4 md:flex-row md:flex-wrap md:items-end" data-no-loading>
        <div><label class="label">من</label><input type="date" name="from" value="{{ $from->toDateString() }}" class="input"></div>
        <div><label class="label">إلى</label><input type="date" name="to" value="{{ $to->toDateString() }}" class="input"></div>
        <div><label class="label">الموظف</label>
            <select name="user_id" class="input"><option value="">الكل</option>
                @foreach($users as $u)<option value="{{ $u->id }}" @selected(($filters['user_id'] ?? null) == $u->id)>{{ $u->name }}</option>@endforeach
            </select></div>
        <div><label class="label">الطرف</label>
            <select name="party_type" class="input"><option value="">الكل</option>
                @foreach(\App\Enums\ContactParty::cases() as $p)<option value="{{ $p->value }}" @selected(($filters['party_type'] ?? null) === $p->value)>{{ $p->label() }}</option>@endforeach
            </select></div>
        <div><label class="label">النتيجة</label>
            <select name="result" class="input"><option value="">الكل</option>
                @foreach(\App\Enums\ContactResult::cases() as $r)<option value="{{ $r->value }}" @selected(($filters['result'] ?? null) === $r->value)>{{ $r->label() }}</option>@endforeach
            </select></div>
        <div class="flex gap-2"><button class="btn btn-primary">تصفية</button><a href="{{ route('contacts.index') }}" class="btn btn-light">مسح</a></div>
    </form>

    <div class="grid gap-5 xl:grid-cols-3 mb-5">
        <section class="card xl:col-span-2">
            <div class="card-header"><h2 class="card-title">إحصائيات الموظفين</h2></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>الموظف</th><th>الإجمالي</th><th>الزبون</th><th>نسبة رد الزبون</th><th>المندوب</th><th>نسبة رد المندوب</th></tr></thead>
                <tbody>
                @forelse($employees as $e)
                    <tr>
                        <td class="font-bold">{{ $e['user']->name }}</td>
                        <td class="tabular-nums">{{ $e['total'] }}</td>
                        <td class="tabular-nums">{{ $e['customer'] }}</td>
                        <td class="tabular-nums">{{ $e['customer_rate'] !== null ? $e['customer_rate'].'%' : '—' }}</td>
                        <td class="tabular-nums">{{ $e['courier'] }}</td>
                        <td class="tabular-nums">{{ $e['courier_rate'] !== null ? $e['courier_rate'].'%' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-slate-500">لا توجد اتصالات في هذه الفترة.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
        <section class="card">
            <div class="card-header"><h2 class="card-title">اتصالات الإدارة بالمتاجر</h2></div>
            <div class="divide-y divide-slate-100 text-sm">
                @forelse($stores as $s)
                    <div class="px-5 py-2 flex justify-between"><a class="font-bold text-navy-700" href="{{ route('stores.show', $s['store']) }}">{{ $s['store']->name }}</a>
                        <span class="tabular-nums text-slate-600">{{ $s['count'] }} — آخر اتصال {{ $s['last_at']->format('m/d H:i') }}</span></div>
                @empty
                    <p class="px-5 py-3 text-slate-500">لا توجد اتصالات بالمتاجر.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="card">
        <div class="card-header"><h2 class="card-title">الاتصالات المسجلة يدوياً</h2><span class="badge badge-gray">{{ $attempts->total() }}</span></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>الوقت</th><th>الموظف</th><th>الطرف</th><th>الكود / المتجر</th><th>النتيجة</th><th>محاولة</th><th>ملاحظة</th></tr></thead>
            <tbody>
            @forelse($attempts as $a)
                <tr>
                    <td class="tabular-nums">{{ $a->contacted_at->format('Y/m/d H:i') }}</td>
                    <td>{{ $a->user?->name }}</td>
                    <td><x-badge :enum="$a->party_type"/></td>
                    <td>@if($a->shipment_code)<a class="ltr text-navy-700 underline" href="{{ route('shipments.show', $a->shipment_code) }}">{{ $a->shipment_code }}</a>@else {{ $a->store?->name ?? '—' }} @endif</td>
                    <td><x-badge :enum="$a->result"/></td>
                    <td class="tabular-nums">{{ $a->attempt_number }}</td>
                    <td class="text-sm">{{ $a->notes ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-slate-500">لا توجد سجلات.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $attempts->links() }}
    </section>
</x-layouts.app>
