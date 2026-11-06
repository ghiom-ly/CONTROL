<x-layouts.app title="البحث">
    <x-page-header title="البحث" :subtitle="$q ? 'نتائج البحث عن: '.$q : 'ابحث عن موظف أو متجر أو كود شحنة'"/>
    <form method="GET" class="mb-6 flex max-w-xl gap-2" data-no-loading>
        <input name="q" value="{{ $q }}" class="input" placeholder="اكتب حرفين على الأقل..." maxlength="100" autofocus>
        <button class="btn btn-primary"><x-icon name="search" class="size-4"/> بحث</button>
    </form>
    @if(mb_strlen($q) >= 2)
        <div class="grid gap-6 lg:grid-cols-3">
            @foreach(['users' => 'الموظفون', 'stores' => 'المتاجر', 'shipments' => 'الشحنات'] as $key => $label)
                <div class="card">
                    <div class="card-header"><h2 class="card-title">{{ $label }}</h2><span class="badge badge-gray">{{ $results[$key]->count() }}</span></div>
                    <div class="divide-y divide-slate-100">
                        @forelse($results[$key] as $item)
                            @if($key === 'users')
                                <a href="{{ route('users.show', $item) }}" class="block px-5 py-3 hover:bg-slate-50"><p class="font-semibold">{{ $item->name }}</p><p class="text-xs text-slate-500">{{ $item->role->display_name }} · {{ $item->username }}</p></a>
                            @elseif($key === 'stores')
                                <div class="px-5 py-3"><p class="font-semibold">{{ $item->name }}</p><p class="text-xs text-slate-500">{{ $item->code }}</p></div>
                            @else
                                <div class="px-5 py-3"><p class="font-semibold ltr text-right"><a class="text-navy-700 underline" href="{{ route('shipments.show', $item->code) }}">{{ $item->code }}</a> @if($ext = AppSupportShipmentUrl::for($item->code))<a href="{{ $ext }}" target="_blank" rel="noopener noreferrer" class="text-xs text-slate-500" title="فتح في منظومة الشحن">↗</a>@endif</p><p class="text-xs text-slate-500">{{ $item->store?->name }}</p></div>
                            @endif
                        @empty
                            <p class="px-5 py-8 text-center text-sm text-slate-400">لا توجد نتائج</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-layouts.app>
