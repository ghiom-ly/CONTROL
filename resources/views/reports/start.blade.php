<x-layouts.app title="بدء الشفت">
    <x-page-header title="بدء الشفت" subtitle="قبل أي اتصال: سجّل لكل متجر عدد طلبياته ونسبة التسليم الحالية — بدء الشفت يُسجَّل كحضور"/>

    <form method="POST" action="{{ route('reports.start') }}" class="card p-5 max-w-3xl space-y-4"
          x-data="{ base: {{ $myStores->count() }}, extra: [], others: @js($otherStores->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])) }">
        @csrf
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>المتجر</th><th>عدد الطلبيات</th><th>نسبة التسليم %</th></tr></thead>
                <tbody>
                @forelse($myStores as $i => $s)
                    <tr>
                        <td class="font-bold">{{ $s->name }} <input type="hidden" name="stores[{{ $i }}][store_id]" value="{{ $s->id }}"></td>
                        <td><input name="stores[{{ $i }}][start_orders]" inputmode="numeric" value="{{ old("stores.$i.start_orders") }}" class="input !w-28"></td>
                        <td><input name="stores[{{ $i }}][start_rate]" inputmode="decimal" value="{{ old("stores.$i.start_rate") }}" class="input !w-28"></td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-sm text-slate-500">لا توجد متاجر مُسندة إليك — أضف المتاجر التي تعمل عليها من الأسفل.</td></tr>
                @endforelse
                <template x-for="(row, k) in extra" :key="k">
                    <tr>
                        <td>
                            <select class="input" :name="`stores[${base + k}][store_id]`" x-model="row.id">
                                <option value="">— اختر متجراً —</option>
                                <template x-for="o in others" :key="o.id"><option :value="o.id" x-text="o.name"></option></template>
                            </select>
                        </td>
                        <td><input class="input !w-28" inputmode="numeric" :name="`stores[${base + k}][start_orders]`"></td>
                        <td><input class="input !w-28" inputmode="decimal" :name="`stores[${base + k}][start_rate]`"></td>
                    </tr>
                </template>
                </tbody>
            </table>
        </div>
        <button type="button" class="btn btn-light btn-sm" x-on:click="extra.push({ id: '' })">+ متجر آخر (خارج متاجري)</button>
        <p class="hint">اترك المتجر فارغاً إن لم تعمل عليه اليوم. النسبة العامة تُحسب تلقائياً من نسب المتاجر.</p>
        @error('stores')<p class="field-error">{{ $message }}</p>@enderror
        @foreach($errors->get('stores.*') as $msgs) @foreach($msgs as $m)<p class="field-error">{{ $m }}</p>@endforeach @endforeach
        @error('shift')<p class="field-error">{{ $message }}</p>@enderror
        <button class="btn btn-accent w-full">بدء الشفت</button>
    </form>

    @can('imports.create')
        <div class="card p-5 max-w-3xl mt-5">
            <h2 class="card-title mb-1">أو: ابدأ الشفت من ملف منظومة الشحن</h2>
            <p class="hint mb-3">يقرأ النظام طلبيات ونسبة تسليم كل متجر من الملف.</p>
            <form method="POST" action="{{ route('imports.shift.upload') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
                @csrf
                <input type="hidden" name="kind" value="shift_start">
                <input type="file" name="file" accept=".xlsx,.xls,.csv" required class="text-sm">
                <button class="btn btn-primary"><x-icon name="upload" class="size-4"/> رفع ملف البداية</button>
            </form>
            @error('file')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    @endcan
</x-layouts.app>
