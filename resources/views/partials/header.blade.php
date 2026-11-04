@php
    $user = auth()->user();
    $unread = $user->unreadNotifications()->latest()->limit(8)->get();
    $unreadCount = $user->unreadNotifications()->count();
@endphp
<header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
    <button type="button" class="btn-ghost btn !p-2" x-on:click="$store.layout.toggle()" aria-label="القائمة">
        <x-icon name="menu"/>
    </button>

    <form action="{{ route('search') }}" method="GET" class="relative hidden flex-1 max-w-md sm:block" data-no-loading>
        <x-icon name="search" class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400"/>
        <input type="search" name="q" value="{{ request('q') }}" placeholder="بحث عن موظف، متجر، أو كود شحنة..."
               class="input !rounded-full !bg-slate-50 !py-2 ps-9" maxlength="100">
    </form>

    <div class="ms-auto flex items-center gap-2">
        <a href="{{ route('search') }}" class="btn-ghost btn !p-2 sm:hidden" aria-label="بحث"><x-icon name="search"/></a>

        {{-- الإشعارات --}}
        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
            <button type="button" class="btn-ghost btn relative !p-2" x-on:click="open = !open" aria-label="الإشعارات">
                <x-icon name="bell"/>
                @if($unreadCount)
                    <span class="absolute -top-0.5 -end-0.5 grid min-w-5 h-5 place-items-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
                @endif
            </button>
            <div x-show="open" x-cloak x-transition class="absolute end-0 mt-2 w-80 max-w-[90vw] card overflow-hidden shadow-xl">
                <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100">
                    <p class="font-bold text-navy-900">الإشعارات</p>
                    @if($unreadCount)
                        <form method="POST" action="{{ route('notifications.read-all') }}">@csrf
                            <button class="text-xs font-semibold text-brand-600 hover:underline">تعليم الكل كمقروء</button>
                        </form>
                    @endif
                </div>
                <div class="max-h-80 overflow-y-auto divide-y divide-slate-100">
                    @forelse($unread as $n)
                        <a href="{{ $n->data['url'] ?? '#' }}" class="block px-4 py-3 hover:bg-slate-50">
                            <p class="text-sm font-semibold text-slate-700">{{ $n->data['title'] ?? 'إشعار' }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $n->data['message'] ?? '' }}</p>
                            <p class="mt-1 text-[11px] text-slate-400">{{ $n->created_at->diffForHumans() }}</p>
                        </a>
                    @empty
                        <p class="px-4 py-8 text-center text-sm text-slate-400">لا توجد إشعارات جديدة</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ملف المستخدم --}}
        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
            <button type="button" class="flex items-center gap-2 rounded-xl p-1.5 hover:bg-slate-100" x-on:click="open = !open">
                <span class="grid size-9 place-items-center rounded-full bg-navy-100 text-sm font-bold text-navy-700">{{ $user->initials() }}</span>
                <span class="hidden text-right md:block">
                    <span class="block text-sm font-bold text-navy-900 leading-4">{{ $user->name }}</span>
                    <span class="block text-xs text-slate-500">{{ $user->role->display_name }}</span>
                </span>
                <x-icon name="chevron-down" class="hidden size-4 text-slate-400 md:block"/>
            </button>
            <div x-show="open" x-cloak x-transition class="absolute end-0 mt-2 w-56 card py-1 shadow-xl">
                <a href="{{ route('users.show', $user) }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-slate-50"><x-icon name="eye" class="size-4"/> ملفي وأدائي</a>
                <a href="{{ route('password.edit') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-slate-50"><x-icon name="key" class="size-4"/> تغيير كلمة المرور</a>
                <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100 mt-1 pt-1">@csrf
                    <button class="flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50"><x-icon name="logout" class="size-4"/> تسجيل الخروج</button>
                </form>
            </div>
        </div>
    </div>
</header>
