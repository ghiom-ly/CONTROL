<x-layouts.app title="التقارير الإدارية">
    <x-page-header title="التقارير الإدارية" subtitle="استخرج أي تقرير PDF أو Excel أو اطبعه — بهوية الشركة ومن التقارير المعتمدة فقط"/>
    @if($errors->any())
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif

    @php $groups = ['management' => 'للإدارة والشركاء', 'employees' => 'للموظفين', 'stores' => 'للمتاجر']; @endphp
    @foreach($groups as $aud => $label)
        <h2 class="mb-3 mt-6 text-sm font-bold text-slate-500">{{ $label }}</h2>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach($reports->filter(fn ($r) => $r->audience() === $aud) as $r)
                @php $needs = $r->needs(); @endphp
                <form method="GET" target="_blank" class="card flex flex-col gap-3 p-4" x-data="{ preset: 'week' }" data-no-loading>
                    <h3 class="font-bold text-navy-900">{{ $r->title() }}</h3>
                    @if(in_array('period', $needs, true) || $needs === ['period'])
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <select name="period" x-model="preset" class="input !w-32">
                                <option value="day">يوم</option><option value="week">أسبوع</option><option value="month">شهر</option><option value="custom">مخصصة</option>
                            </select>
                            <template x-if="preset === 'custom'"><span class="flex gap-1">
                                <input type="date" name="from" class="input !w-36" required><input type="date" name="to" class="input !w-36" required>
                            </span></template>
                        </div>
                    @endif
                    @if(in_array('month', $needs, true))
                        <label class="text-sm">الشهر <input type="month" name="month" value="{{ now()->format('Y-m') }}" class="input !w-44"></label>
                    @endif
                    @if(in_array('employee', $needs, true))
                        <select name="user_id" class="input" required><option value="">— اختر الموظف —</option>
                            @foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach
                        </select>
                    @endif
                    @if(in_array('store', $needs, true) || in_array('store?', $needs, true))
                        <input name="store_code" class="input ltr" placeholder="كود المتجر مثل STR-00045 {{ in_array('store?', $needs, true) ? '(اختياري)' : '' }}" @required(in_array('store', $needs, true))>
                    @endif
                    <div class="mt-auto flex flex-wrap gap-2">
                        @foreach(['pdf' => 'PDF', 'excel' => 'Excel', 'print' => 'طباعة'] as $fmt => $fl)
                            <button class="btn btn-light btn-sm" formaction="{{ route('reports-center.export', [$r->key(), $fmt]) }}">{{ $fl }}</button>
                        @endforeach
                    </div>
                </form>
            @endforeach
        </div>
    @endforeach
    <p class="hint mt-4">تقرير المتجر يُرسل أيضاً لصاحبه عبر واتساب من صفحة المتجر.</p>
</x-layouts.app>
