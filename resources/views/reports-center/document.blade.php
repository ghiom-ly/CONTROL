{{-- قالب واحد للتقارير: PDF (mPDF) والطباعة من المتصفح. $data: ReportData، $brand: Branding::header()، $mode: pdf|print --}}
@php
    $cap = 500;   // خادم 1GB: 500 صف ≈ 6 ثوانٍ و100MB في mPDF
    $logo = $brand['logo_path'] ? 'data:'.mime_content_type($brand['logo_path']).';base64,'.base64_encode(file_get_contents($brand['logo_path'])) : null;
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ $data->title }}</title>
    <style>
        body { font-family: {{ $mode === 'pdf' ? 'dejavusans' : "'Cairo', 'Segoe UI', Tahoma, sans-serif" }}; font-size: 11px; color: #0f172a; direction: rtl; }
        .head { border-bottom: 2px solid #1e3a8a; padding-bottom: 8px; margin-bottom: 12px; width: 100%; }
        .head td { vertical-align: middle; }
        .co { font-size: 16px; font-weight: bold; color: #1e3a8a; }
        .muted { color: #64748b; font-size: 10px; }
        h1 { font-size: 15px; margin: 4px 0; color: #0f172a; }
        h2 { font-size: 13px; margin: 14px 0 6px; color: #1e3a8a; }
        table.t { width: 100%; border-collapse: collapse; }
        table.t th { background: #1e3a8a; color: #fff; padding: 5px; font-size: 10px; text-align: right; }
        table.t td { border-bottom: 1px solid #e2e8f0; padding: 4px 5px; text-align: right; }
        table.t tr.r:nth-child(even) td { background: #f8fafc; }
        .sum td { padding: 4px 8px; border: 1px solid #e2e8f0; }
        .sum .k { background: #f1f5f9; font-weight: bold; }
        .empty { color: #64748b; padding: 10px 0; }
        .cap { color: #b45309; font-size: 10px; margin-top: 4px; }
        .foot { margin-top: 16px; color: #64748b; font-size: 9px; border-top: 1px solid #e2e8f0; padding-top: 6px; }
        @media print { .noprint { display: none; } }
    </style>
</head>
<body>
    @if($mode === 'print')
        <p class="noprint" style="text-align:left"><button onclick="window.print()">طباعة</button></p>
        <script>window.addEventListener('load', () => window.print());</script>
    @endif

    <table class="head"><tr>
        <td>
            <div class="co">{{ $brand['name'] }}</div>
            <div class="muted">{{ $brand['phone'] }}{{ $brand['phone'] && $brand['address'] ? ' — ' : '' }}{{ $brand['address'] }}</div>
            <h1>{{ $data->title }}</h1>
            <div class="muted">{{ $data->subtitle }}</div>
        </td>
        @if($logo)<td style="text-align:left; width:140px"><img src="{{ $logo }}" style="max-height:60px; max-width:140px"></td>@endif
    </tr></table>

    @if($data->summary)
        <table class="sum" style="border-collapse:collapse; margin-bottom:8px"><tr>
            @foreach($data->summary as $k => $v)
                <td class="k">{{ $k }}</td><td>{{ $v ?? '—' }}</td>
                @if($loop->iteration % 3 === 0)</tr><tr>@endif
            @endforeach
        </tr></table>
    @endif

    @foreach($data->sections as $s)
        <h2>{{ $s['title'] }}</h2>
        @if(empty($s['rows']))
            <p class="empty">لا توجد بيانات في هذه الفترة</p>
        @else
            <table class="t">
                <thead><tr>@foreach($s['headers'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach(array_slice($s['rows'], 0, $cap) as $row)
                    <tr class="r">@foreach($row as $cell)<td>{{ $cell ?? '—' }}</td>@endforeach</tr>
                @endforeach
                </tbody>
            </table>
            @if(count($s['rows']) > $cap)<p class="cap">تم عرض أول {{ $cap }} — استخدم Excel للكامل ({{ count($s['rows']) }} صفاً)</p>@endif
        @endif
    @endforeach

    @if(empty($data->sections) && empty($data->summary))
        <p class="empty">لا توجد بيانات في هذه الفترة</p>
    @endif

    <div class="foot">استُخرج {{ now()->format('Y/m/d H:i') }}@if($data->internal && auth()->check()) — بواسطة {{ auth()->user()->name }}@endif — {{ $brand['name'] }}</div>
</body>
</html>
