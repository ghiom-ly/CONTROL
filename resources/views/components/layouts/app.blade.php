<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' | ' : '' }}NOURS STORE MONITORING</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen" x-data>
<div class="flex min-h-screen">
    @include('partials.sidebar')

    <div class="flex min-w-0 flex-1 flex-col transition-[margin] duration-300 lg:ms-72"
         :class="{ 'lg:ms-20!': $store.layout.collapsed }">
        @include('partials.header')

        <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
            <x-flash/>
            {{ $slot }}
        </main>

        <footer class="px-6 py-4 text-center text-xs text-slate-400">
            NOURS STORE MONITORING — {{ \App\Models\SystemSetting::get('company_name', 'شركة النورس') }} © {{ now()->year }}
        </footer>
    </div>
</div>
@stack('scripts')
</body>
</html>
