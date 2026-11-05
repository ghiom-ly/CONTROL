<x-layouts.app :title="$store->name">
    <x-page-header :title="$store->name" :subtitle="$store->code.' — '.($store->assignedUser?->name ?? 'غير مسند لموظف')">
        <x-slot:actions>
            @can('messages.send')
                <a href="{{ route('stores.message', $store) }}" class="btn btn-accent"><x-icon name="phone" class="size-4"/> إرسال رسالة</a>
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

    @isset($insights)
        @include('stores._insights')
    @endisset

    @can('update', $store)
        <x-modal name="edit-store" title="تعديل المتجر" :show="$errors->any() && old('_modal') === 'edit'">
            @include('stores._form', ['store' => $store])
        </x-modal>
    @endcan
</x-layouts.app>
