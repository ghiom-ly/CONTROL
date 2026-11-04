<x-layouts.app :title="\App\Models\ImportBatch::TYPES[$batch->report_type] ?? 'استيراد'">
    @php
        $m = old() ? ['layout' => old('layout'), 'columns' => old('columns', []), 'delivered_values' => old('delivered_values', [])] : ($batch->column_mapping ?? ['layout' => 'orders', 'columns' => [], 'delivered_values' => []]);
        $headers = $batch->meta['headers'] ?? [];
        $choices = $batch->meta['store_choices'] ?? [];
        $fmt = fn ($v) => $v === null ? 'غير متوفر' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    @endphp
    <x-page-header :title="(\App\Models\ImportBatch::TYPES[$batch->report_type] ?? 'استيراد').' — '.$batch->original_name"
                   :subtitle="$batch->batch_number.' — '.$batch->total_rows.' صف — '.$batch->status->label()"/>

    @if($editable)
        <form method="POST" action="{{ route('imports.mapping', $batch) }}" class="card p-5 space-y-4" x-data="{ layout: @js($m['layout'] ?? 'orders') }">
            @csrf
            <h2 class="card-title">مطابقة الأعمدة</h2>
            @if(($batch->meta['mapping_source'] ?? null) === 'saved')
                <p class="hint">طُبّقت مطابقة محفوظة لنفس شكل الملف — يمكنك تعديلها.</p>
            @endif
            <div class="flex flex-wrap gap-4 text-sm">
                <label class="flex items-center gap-2"><input type="radio" name="layout" value="orders" x-model="layout"> صف لكل طلبية</label>
                <label class="flex items-center gap-2"><input type="radio" name="layout" value="stores" x-model="layout"> صف لكل متجر (ملخّص)</label>
            </div>
            @php
                $fields = [
                    'store' => ['عمود المتجر', 'both', true],
                    'status' => ['عمود الحالة', 'orders', true],
                    'code' => ['عمود الكود (اختياري)', 'orders', false],
                    'total' => ['عمود الإجمالي', 'stores', true],
                    'delivered' => ['عمود «تم التسليم»', 'stores', false],
                    'rate' => ['عمود النسبة', 'stores', false],
                ];
            @endphp
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach($fields as $key => [$label, $for, $req])
                    <div @if($for !== 'both') x-show="layout === '{{ $for }}'" @endif>
                        <label class="label">{{ $label }} @if($req)<span class="text-red-500">*</span>@endif</label>
                        <select name="columns[{{ $key }}]" class="input">
                            <option value="">—</option>
                            @foreach($headers as $h)<option value="{{ $h }}" @selected(($m['columns'][$key] ?? null) === $h)>{{ $h }}</option>@endforeach
                        </select>
                        @error("columns.$key")<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>

            @if($preview && ($m['layout'] ?? null) === 'orders' && $preview['status_values'])
                <div>
                    <p class="label">أي الحالات تعني «تم التسليم»؟</p>
                    <div class="flex flex-wrap gap-3 text-sm">
                        @foreach($preview['status_values'] as $sv)
                            <label class="flex items-center gap-1"><input type="checkbox" name="delivered_values[]" value="{{ $sv }}"
                                @checked(in_array(\App\Support\ArabicNormalizer::normalize($sv), array_map([\App\Support\ArabicNormalizer::class, 'normalize'], $m['delivered_values'] ?? []), true))> {{ $sv }}</label>
                        @endforeach
                    </div>
                </div>
            @else
                @foreach($m['delivered_values'] ?? [] as $dv)<input type="hidden" name="delivered_values[]" value="{{ $dv }}">@endforeach
            @endif

            @if($preview && $preview['unmatched'])
                <div>
                    <p class="label">متاجر في الملف غير معروفة — اختر المتجر المقابل (سيُحفظ كاسم بديل)</p>
                    @foreach($preview['unmatched'] as $name)
                        <div class="mb-2 flex flex-wrap items-center gap-2">
                            <span class="font-bold w-48">{{ $name }}</span>
                            <select name="store_choices[{{ $name }}]" class="input !w-64">
                                <option value="">— اختر —</option>
                                <option value="ignore" @selected(($choices[$name] ?? null) === 'ignore')>تجاهل</option>
                                @foreach($stores as $s)<option value="{{ $s->id }}" @selected((string) ($choices[$name] ?? '') === (string) $s->id)>{{ $s->name }}</option>@endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
            @endif
            @foreach($choices as $name => $val)
                @if(! in_array($name, $preview['unmatched'] ?? [], true))<input type="hidden" name="store_choices[{{ $name }}]" value="{{ $val }}">@endif
            @endforeach

            <button class="btn btn-primary">حفظ المطابقة وعرض المعاينة</button>
        </form>
    @endif

    @if($preview)
        <section class="card mt-5">
            <div class="card-header">
                <h2 class="card-title">المعاينة</h2>
                <p class="text-sm">الإجمالي: <b>{{ $preview['total'] ?? 'غير متوفر' }}</b> — المسلَّم: <b>{{ $preview['delivered'] ?? 'غير متوفر' }}</b> — نسبة التسليم: <b>{{ $preview['rate'] === null ? 'غير متوفر' : $fmt($preview['rate']).'%' }}</b></p>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>المتجر في الملف</th><th>متجر النظام</th><th>الإجمالي</th><th>المسلَّم</th><th>النسبة</th></tr></thead>
                    <tbody>
                    @foreach($preview['stores'] as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td>{!! $row['store_id'] ? e($stores->firstWhere('id', $row['store_id'])?->name) : '<span class="badge badge-yellow">غير مطابَق — لا يدخل الحساب</span>' !!}</td>
                            <td class="tabular-nums">{{ $row['total'] }}</td>
                            <td class="tabular-nums">{{ $row['delivered'] ?? 'غير متوفر' }}</td>
                            <td class="tabular-nums">{{ $row['rate'] === null ? 'غير متوفر' : $fmt($row['rate']).'%' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($preview['ignored'])<p class="px-5 py-2 text-sm text-slate-500">صفوف متجاهلة: {{ $preview['ignored'] }}</p>@endif
            @if($preview['rejected'])
                <div class="px-5 pb-4">
                    <p class="label">صفوف مرفوضة ({{ count($preview['rejected']) }})</p>
                    <ul class="text-sm text-red-700 list-disc ps-5">
                        @foreach(array_slice($preview['rejected'], 0, 50) as $rej)<li>الصف {{ $rej['row'] }}: {{ $rej['reason'] }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @if($editable)
                <div class="flex flex-wrap items-center gap-2 p-5 border-t border-slate-100">
                    @if($batch->report_type === 'shift_end' && $batch->report && $batch->report->end_delivery_rate !== null
                        && ($batch->report->value_sources['end_delivery_rate'] ?? 'manual') === 'manual'
                        && $preview['rate'] !== null && round((float) $batch->report->end_delivery_rate, 2) !== round($preview['rate'], 2))
                        <label form="apply-form" class="flex items-center gap-2 text-sm">
                            <input form="apply-form" type="checkbox" name="replace_end_rate" value="1">
                            القيمة الحالية {{ $fmt($batch->report->end_delivery_rate) }}% — قيمة الملف {{ $fmt($preview['rate']) }}%: استبدال بقيمة الملف
                        </label>
                    @endif
                    <form method="POST" action="{{ route('imports.apply', $batch) }}" id="apply-form">@csrf
                        <button class="btn btn-accent" @disabled($preview['total'] === null)>تطبيق على التقرير</button>
                    </form>
                    <form method="POST" action="{{ route('imports.cancel', $batch) }}">@csrf
                        <button class="btn btn-light">إلغاء</button>
                    </form>
                </div>
                @error('apply')<p class="field-error px-5 pb-4">{{ $message }}</p>@enderror
            @endif
        </section>
    @endif
</x-layouts.app>
