<x-layouts.app title="تعيين المتاجر">
    <x-page-header title="تعيين المتاجر" subtitle="حدّد متاجر ثم اختر الموظف — يمكن النقل بين الموظفين أو إلغاء الإسناد">
        <x-slot:actions><a href="{{ route('stores.index') }}" class="btn btn-light">رجوع للمتاجر</a></x-slot:actions>
    </x-page-header>

    <form method="GET" class="mb-4 flex gap-2" data-no-loading>
        <input name="q" value="{{ $q }}" class="input !w-72" placeholder="بحث باسم المتجر أو الكود">
        <button class="btn btn-light">بحث</button>
    </form>

    <form method="POST" action="{{ route('stores.assignment.update') }}" x-data="{ n: 0 }" x-on:change="n = $el.querySelectorAll('input[name=\'store_ids[]\']:checked').length">
        @csrf
        <div class="card sticky top-2 z-10 mb-4 flex flex-wrap items-center gap-2 p-3">
            <span class="text-sm">المحدد: <b x-text="n"></b></span>
            <select name="user_id" class="input !w-64" required>
                <option value="">— إسناد إلى —</option>
                <option value="none">بدون موظف (إلغاء الإسناد)</option>
                @foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach
            </select>
            <button class="btn btn-accent">تطبيق</button>
            @error('user_id')<p class="field-error">{{ $message }}</p>@enderror
            @error('store_ids')<p class="field-error">{{ $message }}</p>@enderror
            @foreach($errors->get('store_ids.*') as $msgs) @foreach($msgs as $m)<p class="field-error">{{ $m }}</p>@endforeach @endforeach
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach($employees->prepend((object) ['id' => 0, 'name' => 'غير مُسندة']) as $e)
                @php $list = $byUser[$e->id] ?? collect(); @endphp
                <div class="card" x-data="{ all: false }">
                    <div class="card-header">
                        <h3 class="card-title {{ $e->id === 0 ? 'text-amber-700' : '' }}">{{ $e->name }} <span class="text-sm text-slate-400">({{ $list->count() }})</span></h3>
                        @if($list->isNotEmpty())
                            <label class="text-xs flex items-center gap-1"><input type="checkbox" x-model="all" x-on:change="$el.closest('.card').querySelectorAll('input[name=\'store_ids[]\']').forEach(c => c.checked = all)"> الكل</label>
                        @endif
                    </div>
                    <div class="max-h-80 overflow-y-auto p-3 space-y-1">
                        @forelse($list as $s)
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="store_ids[]" value="{{ $s->id }}" class="size-4"> {{ $s->name }} <span class="text-xs text-slate-400 ltr">{{ $s->code }}</span></label>
                        @empty
                            <p class="text-sm text-slate-400">لا توجد متاجر</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </form>
</x-layouts.app>
