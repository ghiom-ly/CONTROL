<x-layouts.app :title="'رسالة إلى '.$store->name">
    <x-page-header :title="'رسالة إلى '.$store->name" :subtitle="($store->owner_name ?? '—').' — '.($store->phone ?? 'بلا رقم')">
        <x-slot:actions><a href="{{ route('stores.show', $store) }}" class="btn btn-light">رجوع للمتجر</a></x-slot:actions>
    </x-page-header>

    @if($templates->isEmpty())
        <div class="card"><x-empty-state title="لا توجد قوالب معتمدة" icon="report">اطلب من المدير اعتماد القوالب أولاً.</x-empty-state></div>
    @else
        <form method="GET" class="card p-4 mb-5 flex flex-wrap items-end gap-3" data-no-loading>
            <div>
                <label class="label">القالب</label>
                <select name="template_id" class="input !w-72" onchange="this.form.submit()">
                    @foreach($templates as $t)<option value="{{ $t->id }}" @selected($template?->id === $t->id)>{{ $t->name }} — {{ \App\Models\MessageTemplate::TYPES[$t->type] }}</option>@endforeach
                </select>
            </div>
            @if($template?->type === 'issue')
                <div>
                    <label class="label">المشكلة</label>
                    <select name="issue_id" class="input !w-72" onchange="this.form.submit()">
                        @forelse($issues as $i)<option value="{{ $i->id }}" @selected($issue?->id === $i->id)>{{ $i->issue_number }} — {{ $i->title }}</option>@empty<option value="">لا توجد مشكلات مفتوحة</option>@endforelse
                    </select>
                </div>
            @endif
            @if($template?->type === 'performance')
                <div>
                    <label class="label">الفترة</label>
                    <select name="period" class="input" onchange="this.form.submit()">
                        @foreach(\App\Services\Messages\MessageContext::PERIODS as $k => $l)<option value="{{ $k }}" @selected($period === $k)>{{ $l }}</option>@endforeach
                    </select>
                </div>
            @endif
            @foreach(request('codes', []) as $c)<input type="hidden" name="codes[]" value="{{ $c }}">@endforeach
        </form>

        <form method="POST" action="{{ route('stores.message.send', $store) }}" target="_blank" class="card p-5 space-y-3"
              x-data="{ body: @js(old('body', $rendered['text'])), copied: false }" data-no-loading>
            @csrf
            <input type="hidden" name="template_id" value="{{ $template->id }}">
            <input type="hidden" name="period" value="{{ $period }}">
            @if($issue)<input type="hidden" name="issue_id" value="{{ $issue->id }}">@endif
            @if($template->type === 'open_codes') @foreach($codes as $c)<input type="hidden" name="codes[]" value="{{ $c }}">@endforeach @endif

            @if(! $phone)<p class="rounded-lg bg-red-50 p-3 text-sm font-bold text-red-700">رقم المتجر غير صالح لواتساب — عدّله في صفحة المتجر، أو انسخ النص.</p>@endif
            @if($template->type === 'open_codes' && ! $codes)<p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">لا توجد أكواد مفتوحة لهذا المتجر.</p>@endif
            @if($template->type === 'issue' && ! $issue)<p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">لا توجد مشكلات مفتوحة على هذا المتجر.</p>@endif
            @if($rendered['missing'])<p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">متغيرات بلا قيمة: {{ '{'.implode('}، {', $rendered['missing']).'}' }}</p>@endif
            <p x-show="body.length > {{ \App\Http\Controllers\StoreMessageController::LONG }}" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">الرسالة طويلة — قد لا يفتحها واتساب كاملة؛ استعمل «نسخ النص» إن لزم.</p>

            <label class="label">نص الرسالة (يمكن تعديله قبل الإرسال)</label>
            <textarea name="body" rows="12" class="input leading-7" x-model="body" maxlength="4000">{{ old('body', $rendered['text']) }}</textarea>
            @foreach(['body', 'template_id', 'phone', 'issue_id'] as $f) @error($f)<p class="field-error">{{ $message }}</p>@enderror @endforeach

            @if($template->type === 'open_codes' && $codes)
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="mark_forwarded" value="1" checked> بعد الإرسال: تعليم هذه الأكواد «تم التحويل للمتجر»</label>
            @endif
            <div class="flex flex-wrap gap-2">
                <button class="btn btn-accent" @disabled(! $phone)><x-icon name="phone" class="size-4"/> إرسال عبر واتساب</button>
                <button type="button" class="btn btn-light" x-on:click="navigator.clipboard.writeText(body); copied = true; setTimeout(() => copied = false, 2000)">
                    <span x-text="copied ? 'تم النسخ ✓' : 'نسخ النص'"></span>
                </button>
            </div>
            <p class="hint">يُفتح واتساب في تبويب جديد والرسالة مكتوبة — اضغط «إرسال» هناك. تُسجَّل الرسالة في سجل المتجر.</p>
        </form>

        @if($history->isNotEmpty())
            <div class="card p-5 mt-5 text-sm">
                <h3 class="card-title mb-2">آخر الرسائل لهذا المتجر</h3>
                @foreach($history as $m)<p class="border-b border-slate-100 py-1">{{ $m->created_at->format('Y/m/d H:i') }} — {{ $m->sender->name }} — {{ \Illuminate\Support\Str::limit($m->body, 80) }}</p>@endforeach
            </div>
        @endif
    @endif
</x-layouts.app>
