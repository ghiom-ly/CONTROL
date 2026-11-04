@props(['period', 'keep' => []])
{{-- فلتر الفترة الموحد: يُعيد تحميل الصفحة بالمعاملات المختارة --}}
<form method="GET" x-data="{ preset: '{{ $period->preset }}' }" class="flex flex-wrap items-center gap-2" data-no-loading>
    @foreach($keep as $k => $v)
        @if($v !== null && $v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
    @endforeach
    <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-1.5 shadow-sm">
        <x-icon name="calendar" class="size-4 text-slate-400"/>
        <select name="period" x-model="preset" x-on:change="if (preset !== 'custom') $el.form.submit()"
                class="border-0 bg-transparent py-1 pe-6 text-sm font-semibold text-navy-800 focus:ring-0">
            @foreach(\App\Support\Period::PRESETS as $key => $label)
                <option value="{{ $key }}" @selected($period->preset === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <template x-if="preset === 'custom'">
        <div class="flex flex-wrap items-center gap-2">
            <input type="date" name="from" value="{{ $period->from->toDateString() }}" class="input !py-2 !w-auto" required>
            <span class="text-slate-400">إلى</span>
            <input type="date" name="to" value="{{ $period->to->toDateString() }}" class="input !py-2 !w-auto" required>
            <button class="btn btn-primary btn-sm !py-2">تطبيق</button>
        </div>
    </template>
</form>
