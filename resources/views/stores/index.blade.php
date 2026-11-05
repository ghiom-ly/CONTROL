<x-layouts.app title="المتاجر">
    <x-page-header title="المتاجر" subtitle="قائمة المتاجر والموظف المسؤول عن كل متجر">
        <x-slot:actions>
            @can('create', \App\Models\Store::class)
                <a href="{{ route('stores.import') }}" class="btn btn-light"><x-icon name="upload" class="size-4"/> استيراد من Excel</a>
                @if(Route::has('stores.assignment'))<a href="{{ route('stores.assignment') }}" class="btn btn-light"><x-icon name="users" class="size-4"/> تعيين المتاجر</a>@endif
                <button type="button" class="btn btn-accent" x-on:click="$dispatch('open-modal', 'create-store')"><x-icon name="plus" class="size-4"/> إضافة متجر</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4 sm:max-w-3xl">
        @foreach(\App\Enums\StoreStatus::cases() as $st)
            <x-stat-card :label="$st->label()" :value="(int) ($counts[$st->value] ?? 0)" icon="store" :tone="$st->color()" class="!p-4"/>
        @endforeach
    </div>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 p-4 border-b border-slate-100 md:flex-row md:items-end" data-no-loading>
            <div class="flex-1">
                <label class="label">بحث</label>
                <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="اسم المتجر، الكود أو الهاتف" class="input" maxlength="100">
            </div>
            <div class="md:w-48">
                <label class="label">الموظف المسؤول</label>
                <select name="assigned_user_id" class="input">
                    <option value="">الكل</option>
                    @foreach($employees as $emp)<option value="{{ $emp->id }}" @selected(($filters['assigned_user_id'] ?? null) == $emp->id)>{{ $emp->name }}</option>@endforeach
                </select>
            </div>
            <div class="md:w-40">
                <label class="label">الحالة</label>
                <select name="status" class="input">
                    <option value="">الكل</option>
                    @foreach(\App\Enums\StoreStatus::options() as $val => $label)<option value="{{ $val }}" @selected(($filters['status'] ?? null) === $val)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="md:w-28">
                <label class="label">في الصفحة</label>
                <select name="per_page" class="input">
                    @foreach([20, 50, 100, 200, 500] as $n)<option value="{{ $n }}" @selected((int) ($filters['per_page'] ?? config('nours.per_page')) === $n)>{{ $n }}</option>@endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button class="btn btn-primary">تصفية</button>
                <a href="{{ route('stores.index') }}" class="btn btn-light">مسح</a>
            </div>
        </form>

        @if($stores->isEmpty())
            <x-empty-state title="لا توجد متاجر مطابقة" icon="store">جرّب تعديل معايير البحث أو أضف متجراً جديداً.</x-empty-state>
        @else
            @php $canDelete = auth()->user()->can('bulkDelete', \App\Models\Store::class); @endphp
            <div @if($canDelete) x-data="{ n: 0, all: false, count() { this.n = $root.querySelectorAll('input[name=\'store_ids[]\']:checked').length } }" x-on:change="count()" @endif>
            @if($canDelete)
                <form method="POST" action="{{ route('stores.bulk-destroy') }}" id="bulk-delete" data-no-loading
                      class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-rose-50/40 p-3 text-sm"
                      x-on:submit="if (!confirm(all ? 'سيتم حذف كل المتاجر المطابقة للبحث ({{ $stores->total() }}). المتاجر التي لها تقارير ستُؤرشف بدل الحذف. متأكد؟' : 'سيتم حذف ' + n + ' متجر نهائياً (ومن له تقارير يُؤرشف). متأكد؟')) $event.preventDefault()">
                    @csrf @method('DELETE')
                    @foreach(['q', 'status', 'assigned_user_id', 'per_page'] as $f)
                        @if(filled($filters[$f] ?? null))<input type="hidden" name="{{ $f }}" value="{{ $filters[$f] }}">@endif
                    @endforeach
                    <span>المحدد: <b x-text="all ? {{ $stores->total() }} : n"></b></span>
                    @if($stores->total() > $stores->count())
                        <label class="flex items-center gap-1"><input type="checkbox" name="all_matching" value="1" x-model="all" class="size-4"> تحديد كل المتاجر المطابقة ({{ number_format($stores->total()) }})</label>
                    @endif
                    <button class="btn btn-sm bg-rose-600 text-white hover:bg-rose-700" x-bind:disabled="!all && n === 0"><x-icon name="trash" class="size-4"/> حذف المحدد</button>
                    @error('store_ids')<p class="field-error">{{ $message }}</p>@enderror
                </form>
            @endif
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        @if($canDelete)<th class="w-8"><input type="checkbox" class="size-4" title="تحديد كل الصفحة" x-on:change="$root.querySelectorAll('input[name=\'store_ids[]\']').forEach(c => c.checked = $event.target.checked)"></th>@endif
                        <th>المتجر</th><th>الكود</th><th>الهاتف</th><th>الموظف المسؤول</th><th>الحالة</th><th></th></tr></thead>
                    <tbody>
                    @foreach($stores as $s)
                        <tr>
                            @if($canDelete)<td><input type="checkbox" name="store_ids[]" value="{{ $s->id }}" form="bulk-delete" class="size-4" x-bind:disabled="all"></td>@endif
                            <td class="font-bold text-navy-900">{{ $s->name }}</td>
                            <td class="ltr text-right text-slate-600">{{ $s->code }}</td>
                            <td class="ltr text-right text-slate-600">{{ $s->phone ?? '—' }}</td>
                            <td>{{ $s->assignedUser?->name ?? 'غير مسند' }}</td>
                            <td><x-badge :enum="$s->status"/></td>
                            <td class="text-left whitespace-nowrap">
                                <a href="{{ route('stores.show', $s) }}" class="btn btn-light btn-sm"><x-icon name="eye" class="size-4"/> التفاصيل</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            </div>
            {{ $stores->links() }}
        @endif
    </div>

    @can('create', \App\Models\Store::class)
        <x-modal name="create-store" title="إضافة متجر جديد" :show="$errors->any() && old('_modal') !== 'edit'">
            @include('stores._form', ['store' => null])
        </x-modal>
    @endcan
</x-layouts.app>
