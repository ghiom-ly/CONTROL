<x-layouts.app title="معاينة التقارير القديمة">
    <x-page-header title="معاينة التقارير القديمة" :subtitle="count($items).' تقرير — راجع كل تقرير ثم اضغط «إنشاء التقرير»'">
        <x-slot:actions><a href="{{ route('imports.legacy.create') }}" class="btn btn-light">لصق نص آخر</a></x-slot:actions>
    </x-page-header>

    @foreach($items as $i => $item)
        <form method="POST" action="{{ route('imports.legacy') }}" class="card p-5 mb-5 space-y-3">
            @csrf
            <input type="hidden" name="index" value="{{ $i }}">
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="card-title">ريبورت تاريخ: {{ $item['date'] ?? '؟' }}</h2>
                @if($item['employee_name'])<span class="text-sm text-slate-500">اسم الموظف في النص: {{ $item['employee_name'] }}</span>@endif
            </div>
            @foreach($item['errors'] as $e)<p class="field-error">{{ $e }}</p>@endforeach
            @foreach($item['warnings'] as $w)<p class="text-sm font-semibold text-amber-700">⚠ {{ $w }}</p>@endforeach

            <div class="md:w-64">
                <label class="label">الموظف</label>
                <select name="user_id" class="input">
                    @foreach($users as $u)<option value="{{ $u->id }}" @selected($item['suggested_user_id'] === $u->id)>{{ $u->name }}</option>@endforeach
                </select>
            </div>

            <div>
                <p class="label">المتاجر ({{ count($item['stores']) }})</p>
                @foreach($item['stores'] as $s)
                    <div class="mb-1 flex flex-wrap items-center gap-2 text-sm">
                        <span class="w-48 font-bold">{{ $s['name'] }}</span>
                        <span class="w-20 tabular-nums">{{ $s['count'] ?? 'غير متوفر' }}</span>
                        @if($s['store_id'])
                            <span class="badge badge-green">{{ $stores->firstWhere('id', $s['store_id'])?->name }}</span>
                        @else
                            <select name="store_choices[{{ $s['name'] }}]" class="input !w-56">
                                <option value="">— اختر المتجر —</option>
                                <option value="ignore">تجاهل</option>
                                @foreach($stores as $st)<option value="{{ $st->id }}">{{ $st->name }}</option>@endforeach
                            </select>
                        @endif
                    </div>
                @endforeach
            </div>

            <p class="text-sm"><b>المحاولات:</b> {{ implode(' — ', $item['attempt_times']) ?: 'لا يوجد' }}</p>
            @foreach(['customer' => ['الزبون لم يرد', 'customer_codes'], 'courier' => ['المندوب لم يرد', 'courier_codes']] as $bucket => [$label, $key])
                @if($item[$key])
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <b>{{ $label }}:</b> <span class="ltr">{{ implode(' ', $item[$key]) }}</span>
                        @if(count($item['stores']) !== 1)
                            <select name="list_stores[{{ $bucket }}]" class="input !w-56">
                                <option value="">لأي متجر؟</option>
                                @foreach($stores as $st)<option value="{{ $st->id }}">{{ $st->name }}</option>@endforeach
                            </select>
                        @endif
                    </div>
                @endif
            @endforeach
            <p class="text-sm"><b>الملاحظات:</b> {{ $item['notes'] ?? 'لا يوجد' }}</p>

            @error('store_choices')<p class="field-error">{{ $message }}</p>@enderror
            @error('date')<p class="field-error">{{ $message }}</p>@enderror
            @error('unanswered.customer_store_id')<p class="field-error">{{ $message }}</p>@enderror
            @error('unanswered.courier_store_id')<p class="field-error">{{ $message }}</p>@enderror
            @error('codes')<p class="field-error">{{ $message }}</p>@enderror
            <button class="btn btn-accent" @disabled($item['errors'] !== [])>إنشاء التقرير وإرساله للمراجعة</button>
        </form>
    @endforeach
</x-layouts.app>
