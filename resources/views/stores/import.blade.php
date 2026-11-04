<x-layouts.app title="استيراد المتاجر">
    <x-page-header title="استيراد المتاجر من Excel" subtitle="يضيف المتاجر الجديدة ويحدّث الهاتف وصاحب المتجر للموجودة — لا يحذف شيئاً">
        <x-slot:actions><a href="{{ route('stores.index') }}" class="btn btn-light">رجوع للمتاجر</a></x-slot:actions>
    </x-page-header>

    @if(! $batch)
        <form method="POST" action="{{ route('stores.import.upload') }}" enctype="multipart/form-data" class="card p-5 flex flex-wrap items-center gap-3">
            @csrf
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required class="text-sm">
            <button class="btn btn-primary"><x-icon name="upload" class="size-4"/> رفع الملف</button>
            @error('file')<p class="field-error w-full">{{ $message }}</p>@enderror
        </form>
    @else
        @php $cols = old('columns', $batch->column_mapping['columns'] ?? []); $headers = $batch->meta['headers'] ?? []; @endphp
        @if($batch->status === \App\Enums\ImportStatus::Uploaded)
            <form method="POST" action="{{ route('stores.import.mapping', $batch) }}" class="card p-5 space-y-3">
                @csrf
                <h2 class="card-title">مطابقة الأعمدة — {{ $batch->original_name }} ({{ $batch->total_rows }} صف)</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach(['name' => 'اسم المتجر *', 'phone' => 'الهاتف', 'owner' => 'صاحب المتجر'] as $k => $label)
                        <div>
                            <label class="label">{{ $label }}</label>
                            <select name="columns[{{ $k }}]" class="input">
                                <option value="">—</option>
                                @foreach($headers as $h)<option value="{{ $h }}" @selected(($cols[$k] ?? null) === $h)>{{ $h }}</option>@endforeach
                            </select>
                            @error("columns.$k")<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
                <button class="btn btn-primary">حفظ المطابقة وعرض المعاينة</button>
            </form>
        @endif

        @if($preview)
            @php $labels = ['new' => ['جديد', 'green'], 'update' => ['تحديث', 'blue'], 'same' => ['بلا تغيير', 'gray'], 'rejected' => ['مرفوض', 'red']]; @endphp
            <section class="card mt-5">
                <div class="card-header">
                    <h2 class="card-title">المعاينة</h2>
                    <div class="flex flex-wrap gap-2">
                        @foreach($labels as $k => [$l, $c])<span class="badge badge-{{ $c }}">{{ $l }}: {{ $preview['counts'][$k] }}</span>@endforeach
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>الصف</th><th>اسم المتجر</th><th>الهاتف</th><th>صاحب المتجر</th><th>الإجراء</th></tr></thead>
                        <tbody>
                        @foreach($preview['rows'] as $r)
                            <tr>
                                <td class="tabular-nums">{{ $r['row'] }}</td>
                                <td class="font-bold">{{ $r['name'] ?? '—' }}</td>
                                <td class="ltr text-right">{{ $r['phone'] ?? '—' }}</td>
                                <td>{{ $r['owner'] ?? '—' }}</td>
                                <td>
                                    <span class="badge badge-{{ $labels[$r['action']][1] }}">{{ $labels[$r['action']][0] }}</span>
                                    @if($r['reason'])<span class="text-xs text-red-700">{{ $r['reason'] }}</span>@endif
                                    @if($r['archived'])<span class="text-xs text-slate-500">(متجر مؤرشف — يبقى مؤرشفاً)</span>@endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if($batch->status === \App\Enums\ImportStatus::Uploaded)
                    <form method="POST" action="{{ route('stores.import.apply', $batch) }}" class="p-5 border-t border-slate-100">@csrf
                        <button class="btn btn-accent" @disabled($preview['counts']['new'] + $preview['counts']['update'] === 0)>تأكيد الاستيراد</button>
                    </form>
                @endif
                @error('apply')<p class="field-error px-5 pb-4">{{ $message }}</p>@enderror
            </section>
        @endif
    @endif
</x-layouts.app>
