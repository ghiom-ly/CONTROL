<x-layouts.app title="الأكواد المفتوحة">
    <x-page-header title="الأكواد المفتوحة" subtitle="أكواد غير مُجاب عليها من التقارير المعتمدة — مجمّعة حسب المتجر"/>

    <div class="mb-4 flex gap-2">
        <a href="{{ route('open-codes.index', array_merge($filters, ['tab' => 'customer'])) }}" class="btn {{ $tab === 'customer' ? 'btn-primary' : 'btn-light' }}">لتحويلها للمتاجر (الزبون لم يرد) — {{ $counts['customer'] }}</a>
        <a href="{{ route('open-codes.index', array_merge($filters, ['tab' => 'courier'])) }}" class="btn {{ $tab === 'courier' ? 'btn-primary' : 'btn-light' }}">عالقة مع المناديب — {{ $counts['courier'] }}</a>
    </div>

    <form method="GET" class="card mb-5 flex flex-col gap-3 p-4 md:flex-row md:items-end" data-no-loading>
        <input type="hidden" name="tab" value="{{ $tab }}">
        <div class="md:w-56">
            <label class="label">المتجر</label>
            <select name="store_id" class="input"><option value="">الكل</option>
                @foreach($stores as $s)<option value="{{ $s->id }}" @selected(($filters['store_id'] ?? null) == $s->id)>{{ $s->name }}</option>@endforeach
            </select>
        </div>
        @if($users->isNotEmpty())
            <div class="md:w-48">
                <label class="label">الموظف</label>
                <select name="user_id" class="input"><option value="">الكل</option>
                    @foreach($users as $u)<option value="{{ $u->id }}" @selected(($filters['user_id'] ?? null) == $u->id)>{{ $u->name }}</option>@endforeach
                </select>
            </div>
        @endif
        <div><label class="label">من</label><input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="input"></div>
        <div><label class="label">إلى</label><input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="input"></div>
        <div class="flex gap-2"><button class="btn btn-primary">تصفية</button><a href="{{ route('open-codes.index', ['tab' => $tab]) }}" class="btn btn-light">مسح</a></div>
    </form>

    @if($groups->isEmpty())
        <div class="card"><x-empty-state title="لا توجد أكواد مفتوحة" icon="check-circle"/></div>
    @endif

    @foreach($groups as $storeId => $items)
        @php $store = $items->first()->store; @endphp
        <form method="POST" action="{{ route('open-codes.mark') }}" class="card mb-4" x-data="{ all: false }">
            @csrf
            <div class="card-header">
                <h3 class="card-title">{{ $store->name }} <span class="text-sm text-slate-400">({{ $items->count() }})</span></h3>
                @if($tab === 'customer' && Route::has('stores.message'))
                    @can('messages.send')
                        @php $ot = \App\Models\MessageTemplate::where('status', 'approved')->where('type', 'open_codes')->value('id'); @endphp
                        <a href="{{ route('stores.message', ['store' => $store, 'template_id' => $ot, 'codes' => $items->pluck('code')->all()]) }}" class="btn btn-accent btn-sm ms-auto"><x-icon name="phone" class="size-4"/> إرسال للمتجر</a>
                    @endcan
                @endif
                <button type="button" class="btn btn-light btn-sm" x-data="{ done: false }"
                        x-on:click="navigator.clipboard.writeText(@js($items->pluck('code')->implode(' '))); done = true; setTimeout(() => done = false, 2000)">
                    <span x-text="done ? 'تم النسخ ✓' : 'نسخ الأكواد'"></span>
                </button>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        @if($canMark)<th><input type="checkbox" x-model="all" class="size-4"></th>@endif
                        <th>الكود</th><th>الحالة</th><th>آخر متابعة</th><th>الموظف</th><th>عدد المتابعات</th>
                    </tr></thead>
                    <tbody>
                    @foreach($items as $s)
                        <tr>
                            @if($canMark)<td><input type="checkbox" name="shipment_ids[]" value="{{ $s->id }}" :checked="all" class="size-4"></td>@endif
                            <td class="ltr text-right font-bold">{{ $s->code }}</td>
                            <td><x-badge :enum="$s->processing_status"/></td>
                            <td class="text-xs">{{ $s->last_followup_at?->format('Y/m/d H:i') ?? '—' }}</td>
                            <td>{{ $s->lastFollowupUser?->name ?? '—' }}</td>
                            <td class="tabular-nums">{{ $s->followups_count }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($canMark)
                <div class="flex flex-wrap items-center gap-2 p-4 border-t border-slate-100">
                    <input name="note" class="input !w-72" placeholder="ملاحظة (اختياري)" maxlength="500">
                    <input type="hidden" name="action" value="forwarded" x-ref="action">
                    <button class="btn btn-light btn-sm" x-on:click="$refs.action.value = 'forwarded'">تم التحويل للمتجر</button>
                    <button class="btn btn-accent btn-sm" x-on:click="$refs.action.value = 'resolved'">تم الحل</button>
                </div>
            @endif
        </form>
    @endforeach
</x-layouts.app>
