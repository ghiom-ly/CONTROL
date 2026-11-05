@php
    $user = auth()->user();
    // [label, icon, route|null, params, permission, active pattern, phase(null = متاح)]
    $nav = [
        ['لوحة التحكم', 'dashboard', 'dashboard', [], 'dashboard.view', 'dashboard', null],
        ['إدارة المتاجر', 'store', 'stores.index', [], 'stores.view', 'stores.index|stores.show|stores.import*', null],
        ['تعيين المتاجر', 'users', 'stores.assignment', [], 'stores.manage', 'stores.assignment*', null],
        ['التقارير اليومية', 'report', 'reports.index', [], 'reports.create|reports.view_all', 'reports.index|reports.show|reports.edit', null],
        ['مراجعة التقارير', 'check-circle', 'reports.review.queue', [], 'reports.review', 'reports.review.*', null],
        ['الأكواد المفتوحة', 'alert', 'open-codes.index', [], 'shipments.view', 'open-codes.*', null],
        ['إدخال تقرير جديد', 'plus-doc', 'reports.create', [], 'reports.create', 'reports.create', null],
        ['استيراد الملفات', 'upload', 'imports.index', [], 'imports.view_all', 'imports.*', null],
        ['متابعة الشحنات', 'truck', null, ['shipments'], 'shipments.view', null, 4],
        ['سجل الاتصالات', 'phone', null, ['contacts'], 'contacts.view', null, 4],
        ['إدارة المشكلات', 'alert', null, ['issues'], 'issues.view', null, 4],
        ['الموظفون', 'users', 'users.index', [], 'users.view', 'users.*', null],
        ['الحضور والانصراف', 'clock', 'attendance.index', [], 'users.view', 'attendance.*', null],
        ['التحليلات', 'chart', null, ['analytics'], 'analytics.view', null, 5],
        ['التقارير الإدارية', 'document-chart', null, ['exports'], 'exports.generate', null, 6],
        ['سجل العمليات', 'history', 'audit.index', [], 'audit.view', 'audit.*', null],
        ['الإعدادات', 'cog', 'settings.index', [], 'settings.manage', 'settings.*', null],
    ];
    $allowed = fn ($perm) => collect(explode('|', $perm))->contains(fn ($p) => $user->hasPermission($p));
@endphp

{{-- خلفية الجوال --}}
<div x-show="$store.layout.mobileOpen" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-navy-950/50 lg:hidden"
     x-on:click="$store.layout.mobileOpen = false"></div>

<aside class="fixed inset-y-0 start-0 z-40 flex w-72 translate-x-full flex-col bg-navy-900 text-white transition-all duration-300 lg:translate-x-0
              bg-[radial-gradient(120%_60%_at_100%_0%,rgba(244,114,22,.12),transparent_60%)]"
       :class="{ 'translate-x-0!': $store.layout.mobileOpen, 'lg:w-20!': $store.layout.collapsed && ! $store.layout.mobileOpen }">
    <div class="flex h-16 items-center gap-3 px-5 border-b border-white/10">
        <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-500 font-extrabold text-lg shadow-lg shadow-brand-500/30">ن</div>
        <div class="min-w-0" x-show="!$store.layout.collapsed || $store.layout.mobileOpen">
            <p class="truncate text-sm font-extrabold tracking-wide ltr text-right">NOURS STORE MONITORING</p>
            <p class="text-xs text-navy-200">{{ \App\Models\SystemSetting::get('company_name', 'شركة النورس') }}</p>
        </div>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
        @foreach($nav as [$label, $icon, $route, $params, $perm, $pattern, $phase])
            @continue(! $allowed($perm))
            @php
                $href = $route ? route($route) : route('modules.placeholder', $params);
                $active = $pattern ? request()->routeIs(...explode('|', $pattern)) : request()->is('modules/'.$params[0]);
            @endphp
            <a href="{{ $href }}" class="nav-link {{ $active ? 'active' : '' }}" title="{{ $label }}">
                <x-icon :name="$icon" class="nav-icon size-5 shrink-0"/>
                <span class="flex-1 truncate" x-show="!$store.layout.collapsed || $store.layout.mobileOpen">{{ $label }}</span>
                @if($phase)
                    <span class="rounded-md bg-white/10 px-1.5 py-0.5 text-[10px] font-bold text-navy-200"
                          title="مجدولة في المرحلة {{ $phase }}"
                          x-show="!$store.layout.collapsed || $store.layout.mobileOpen">المرحلة {{ $phase }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    <div class="border-t border-white/10 p-3">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="nav-link w-full" title="تسجيل الخروج">
                <x-icon name="logout" class="size-5 shrink-0"/>
                <span x-show="!$store.layout.collapsed || $store.layout.mobileOpen">تسجيل الخروج</span>
            </button>
        </form>
    </div>
</aside>
