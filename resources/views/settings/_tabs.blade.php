<div class="mb-6 flex gap-1 overflow-x-auto rounded-xl bg-white p-1 border border-slate-200 w-fit">
    @foreach(['settings.index' => 'الإعدادات العامة', 'settings.roles' => 'الأدوار والصلاحيات'] as $r => $l)
        <a href="{{ route($r) }}" class="rounded-lg px-4 py-2 text-sm font-bold whitespace-nowrap {{ request()->routeIs($r) ? 'bg-navy-800 text-white' : 'text-slate-600 hover:bg-slate-100' }}">{{ $l }}</a>
    @endforeach
</div>
