<x-layouts.app :title="$store->name">
    <x-page-header :title="$store->name" :subtitle="$store->code.' — '.($store->assignedUser?->name ?? 'غير مسند لموظف')">
        <x-slot:actions>
            @can('messages.send')
                <a href="{{ route('stores.message', $store) }}" class="btn btn-accent"><x-icon name="phone" class="size-4"/> إرسال رسالة</a>
            @endcan
            @can('create', \App\Models\Issue::class)
                <button type="button" class="btn btn-light" x-on:click="$dispatch('open-modal', 'new-issue')"><x-icon name="alert" class="size-4"/> فتح مشكلة</button>
            @endcan
            @can('update', $store)
                <button type="button" class="btn btn-light" x-on:click="$dispatch('open-modal', 'edit-store')"><x-icon name="pencil" class="size-4"/> تعديل</button>
                @if($store->status !== \App\Enums\StoreStatus::Archived)
                    <x-confirm-form :action="route('stores.archive', $store)" method="PATCH" message="أرشفة المتجر {{ $store->name }}؟ ستبقى تقاريره محفوظة.">
                        <x-icon name="box" class="size-4"/> أرشفة
                    </x-confirm-form>
                @endif
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card p-5 grid gap-4 sm:grid-cols-3 text-sm">
        <div><p class="label">الحالة</p><x-badge :enum="$store->status"/></div>
        <div><p class="label">الهاتف</p><p class="ltr text-right">{{ $store->phone ?? '—' }}</p></div>
        <div><p class="label">صاحب المتجر</p><p>{{ $store->owner_name ?? '—' }}</p></div>
        <div><p class="label">بدء التعامل</p><p>{{ $store->started_at?->format('Y/m/d') ?? '—' }}</p></div>
        <div><p class="label">متوسط الشحنات المتوقع</p><p>{{ $store->expected_avg_shipments ?? 'غير متوفر' }}</p></div>
        <div><p class="label">ملاحظات</p><p>{{ $store->notes ?? '—' }}</p></div>
    </div>

    @php $aliases = $store->aliases()->with('creator:id,name')->latest()->get(); @endphp
    @if($aliases->isNotEmpty())
        <div class="card p-5 mt-5 text-sm">
            <h3 class="font-bold text-navy-900 mb-2">أسماء بديلة (من ملفات الاستيراد)</h3>
            @foreach($aliases as $al)
                <div class="flex items-center gap-3 border-b border-slate-100 py-1">
                    <span class="font-bold">{{ $al->alias }}</span>
                    <span class="text-xs text-slate-400">{{ $al->creator?->name }} — {{ $al->created_at?->format('Y/m/d') }}</span>
                    @can('stores.manage')
                        <form method="POST" action="{{ route('stores.aliases.destroy', $al) }}" class="ms-auto">@csrf @method('DELETE')
                            <button class="btn btn-ghost btn-sm text-red-600"><x-icon name="trash" class="size-4"/></button>
                        </form>
                    @endcan
                </div>
            @endforeach
        </div>
    @endif

    @can('messages.send')
        @php $messages = \App\Models\StoreMessage::where('store_id', $store->id)->with(['sender:id,name', 'template:id,name'])->latest()->limit(20)->get(); @endphp
        @if($messages->isNotEmpty())
            <div class="card p-5 mt-5 text-sm">
                <h3 class="font-bold text-navy-900 mb-2">الرسائل المرسلة ({{ $messages->count() }})</h3>
                @foreach($messages as $m)
                    <div class="border-b border-slate-100 py-2">
                        <p class="text-xs text-slate-400">{{ $m->created_at->format('Y/m/d H:i') }} — {{ $m->sender->name }} — {{ $m->template?->name ?? '—' }}</p>
                        <p class="whitespace-pre-wrap">{{ \Illuminate\Support\Str::limit($m->body, 300) }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    @endcan

    @can('update', $store)
        @php $storeContacts = \App\Models\ContactAttempt::where('store_id', $store->id)->where('party_type', 'store')->with('user:id,name')->latest('contacted_at')->limit(10)->get(); @endphp
        <div class="card mt-5 text-sm" id="store-contacts">
            <div class="card-header"><h3 class="card-title">الاتصال بالمتجر</h3></div>
            <form method="POST" action="{{ route('stores.contacts.store', $store) }}" class="flex flex-wrap items-end gap-2 border-b border-slate-100 p-4">
                @csrf
                <div><label class="label">النتيجة</label>
                    <select name="result" class="input !w-48" required>
                        @foreach(\App\Enums\ContactResult::cases() as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach
                    </select></div>
                <div class="flex-1 min-w-48"><label class="label">ملاحظة (اختياري)</label><input name="notes" class="input" maxlength="1000"></div>
                <button class="btn btn-accent">تسجيل اتصال بالمتجر</button>
            </form>
            @forelse($storeContacts as $c)
                <div class="px-5 py-2 border-b border-slate-100"><span class="tabular-nums text-slate-500">{{ $c->contacted_at->format('Y/m/d H:i') }}</span> — {{ $c->user?->name }} — <x-badge :enum="$c->result"/> @if($c->notes)— {{ $c->notes }}@endif</div>
            @empty
                <p class="px-5 py-3 text-slate-500">لا توجد اتصالات مسجلة بالمتجر.</p>
            @endforelse
        </div>
    @endcan

    @isset($insights)
        @include('stores._insights')
    @endisset

    @can('create', \App\Models\Issue::class)
        <x-modal name="new-issue" title="فتح مشكلة على المتجر" :show="$errors->has('title') || $errors->has('description')">
            <form method="POST" action="{{ route('stores.issues.store', $store) }}" class="space-y-3">
                @csrf
                <div><label class="label">العنوان</label><input name="title" class="input" maxlength="200" required value="{{ old('title') }}">@error('title')<p class="field-error">{{ $message }}</p>@enderror</div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="label">النوع</label><select name="type" class="input">@foreach(\App\Enums\IssueType::cases() as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach</select></div>
                    <div><label class="label">الأولوية</label><select name="priority" class="input">@foreach(\App\Enums\IssuePriority::cases() as $p)<option value="{{ $p->value }}" @selected($p === \App\Enums\IssuePriority::Medium)>{{ $p->label() }}</option>@endforeach</select></div>
                </div>
                <div><label class="label">كود الشحنة (اختياري)</label><input name="shipment_code" class="input ltr" maxlength="80" value="{{ old('shipment_code') }}"></div>
                <div><label class="label">الوصف</label><textarea name="description" rows="3" class="input" maxlength="5000" required>{{ old('description') }}</textarea>@error('description')<p class="field-error">{{ $message }}</p>@enderror</div>
                <button class="btn btn-accent">فتح المشكلة</button>
            </form>
        </x-modal>
    @endcan

    @can('update', $store)
        <x-modal name="edit-store" title="تعديل المتجر" :show="$errors->any() && old('_modal') === 'edit'">
            @include('stores._form', ['store' => $store])
        </x-modal>
    @endcan
</x-layouts.app>
