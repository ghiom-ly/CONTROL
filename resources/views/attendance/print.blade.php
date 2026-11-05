<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>تقرير الحضور الشهري — {{ $user->name }} — {{ $month->format('Y-m') }}</title>
    @vite(['resources/css/app.css'])
    <style>@media print { .no-print { display: none } body { background: #fff } }</style>
</head>
<body class="bg-white p-8 text-sm">
    @php $s = $data['summary']; $h = fn ($m) => $m === null ? '—' : sprintf('%d:%02d', intdiv($m, 60), $m % 60); @endphp
    <div class="no-print mb-4"><button onclick="window.print()" class="btn btn-primary">طباعة</button></div>
    <div class="mb-6 flex items-start justify-between border-b pb-4">
        <div>
            <h1 class="text-xl font-extrabold">تقرير الحضور الشهري</h1>
            <p>{{ \App\Models\SystemSetting::get('company_name', 'شركة النورس') }}</p>
        </div>
        <div class="text-left">
            <p class="font-bold">{{ $user->name }}</p>
            <p>شهر {{ $month->format('Y-m') }}</p>
        </div>
    </div>
    <table class="mb-6 w-full border text-center">
        <tr class="bg-slate-100"><th class="border p-1">حضور</th><th class="border p-1">غياب</th><th class="border p-1">إجازات</th><th class="border p-1">تأخير</th><th class="border p-1">دقائق التأخير</th><th class="border p-1">غير مكتمل</th><th class="border p-1">متوسط الساعات</th><th class="border p-1">التقارير</th><th class="border p-1">معتمدة</th><th class="border p-1">مرفوضة</th></tr>
        <tr><td class="border p-1">{{ $s['present'] }}</td><td class="border p-1">{{ $s['absent'] }}</td><td class="border p-1">{{ $s['leave'] }}</td><td class="border p-1">{{ $s['late'] }}</td><td class="border p-1">{{ $s['late_minutes'] }}</td><td class="border p-1">{{ $s['incomplete'] }}</td><td class="border p-1">{{ $h($s['avg_minutes']) }}</td><td class="border p-1">{{ $s['reports_total'] }}</td><td class="border p-1">{{ $s['reports_approved'] }}</td><td class="border p-1">{{ $s['reports_rejected'] }}</td></tr>
    </table>
    <table class="w-full border">
        <tr class="bg-slate-100"><th class="border p-1">التاريخ</th><th class="border p-1">الحالة</th><th class="border p-1">الحضور</th><th class="border p-1">الانصراف</th><th class="border p-1">المدة</th><th class="border p-1">التأخير</th></tr>
        @foreach($data['days'] as $d)
            @continue($d['status'] === 'future')
            <tr>
                <td class="border p-1">{{ $d['date'] }}</td>
                <td class="border p-1">{{ \App\Services\AttendanceService::STATUS_LABELS[$d['status']] }} {{ $d['leave_type'] }}</td>
                <td class="border p-1">{{ $d['in'] ?? '—' }}</td>
                <td class="border p-1">{{ $d['out'] ?? '—' }}</td>
                <td class="border p-1">{{ $h($d['minutes']) }}</td>
                <td class="border p-1">{{ $d['late_minutes'] ? $d['late_minutes'].' د' : '—' }}</td>
            </tr>
        @endforeach
    </table>
    <p class="mt-8 text-xs text-slate-500">طُبع في {{ now()->format('Y/m/d H:i') }} — الحضور = بدء الشفت، الانصراف = أول إرسال للتقرير.</p>
</body>
</html>
