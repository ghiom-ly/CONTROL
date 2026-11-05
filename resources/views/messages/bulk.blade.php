<x-layouts.app title="رسالة لعدة متاجر">
    <x-page-header title="رسالة لعدة متاجر" subtitle="واتساب يفتح محادثة واحدة في كل مرة — اضغط «إرسال» أمام كل متجر بالترتيب"/>
    @include('messages._tabs')

    <form method="POST" action="{{ route('messages.bulk.preview') }}" class="card p-5 space-y-3" x-data="{ mode: 'pick' }" data-no-loading>
        @csrf
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label class="label">القالب</label>
                <select name="template_id" class="input" required>
                    @foreach($templates as $t)<option value="{{ $t->id }}" @selected($template?->id === $t->id)>{{ $t->name }} — {{ \App\Models\MessageTemplate::TYPES[$t->type] }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="label">الفترة (لملخص الأداء)</label>
                <select name="period" class="input">
                    @foreach(\App\Services\Messages\MessageContext::PERIODS as $k => $l)<option value="{{ $k }}" @selected($period === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="label">المتاجر</label>
                <select class="input" x-model="mode">
                    <option value="pick">اختيار متاجر</option>
                    <option value="employee">متاجر موظف</option>
                    <option value="all">كل المتاجر النشطة</option>
                </select>
            </div>
        </div>
        <div x-show="mode === 'pick'">
            <select name="store_ids[]" multiple size="8" class="input">
                @foreach($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
            </select>
            <p class="hint">اضغط Ctrl لاختيار أكثر من متجر (حتى {{ \App\Http\Controllers\BulkMessageController::MAX }}).</p>
        </div>
        <div x-show="mode === 'employee'">
            <select name="employee_id" class="input !w-64" x-bind:disabled="mode !== 'employee'">
                @foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach
            </select>
        </div>
        <input type="hidden" name="all_active" x-bind:value="mode === 'all' ? 1 : 0">
        @foreach(['template_id', 'store_ids'] as $f) @error($f)<p class="field-error">{{ $message }}</p>@enderror @endforeach
        <button class="btn btn-primary">تجهيز الرسائل</button>
    </form>

    @if($rows !== null)
        <div class="mt-5 space-y-3">
            <p class="text-sm text-slate-600">{{ $rows->count() }} متجر — {{ $rows->whereNull('skipped')->where('phone', '!=', null)->count() }} جاهزة للإرسال</p>
            @foreach($rows as $r)
                <div class="card p-4" x-data="{ sent: false }">
                    <div class="flex flex-wrap items-center gap-2">
                        <b>{{ $r['store']->name }}</b>
                        <span class="ltr text-xs text-slate-500">{{ $r['store']->phone ?? '—' }}</span>
                        @if($r['skipped'])<span class="badge badge-gray">{{ $r['skipped'] }}</span>@elseif(! $r['phone'])<span class="badge badge-red">رقم غير صالح لواتساب</span>@endif
                        <span x-show="sent" class="badge badge-green">فُتح للإرسال ✓</span>
                    </div>
                    @unless($r['skipped'])
                        <form method="POST" action="{{ route('stores.message.send', $r['store']) }}" target="_blank" class="mt-2 space-y-2" x-on:submit="sent = true" data-no-loading>
                            @csrf
                            <input type="hidden" name="template_id" value="{{ $template->id }}">
                            <input type="hidden" name="period" value="{{ $period }}">
                            @if($template->type === 'open_codes') @foreach($r['codes'] as $c)<input type="hidden" name="codes[]" value="{{ $c }}">@endforeach @endif
                            <textarea name="body" rows="5" class="input text-sm leading-6">{{ $r['text'] }}</textarea>
                            <button class="btn btn-accent btn-sm" @disabled(! $r['phone'])>إرسال عبر واتساب</button>
                        </form>
                    @endunless
                </div>
            @endforeach
        </div>
    @endif
</x-layouts.app>
