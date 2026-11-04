<x-layouts.app title="استيراد الملفات">
    <x-page-header title="استيراد الملفات" subtitle="ملفات منظومة الشحن والتقارير القديمة المستوردة">
        <x-slot:actions>
            @can('imports.create')
                <a href="{{ route('imports.legacy.create') }}" class="btn btn-light"><x-icon name="report" class="size-4"/> استيراد تقرير قديم (واتساب)</a>
                <a href="{{ route('reports.create') }}" class="btn btn-accent"><x-icon name="upload" class="size-4"/> رفع ملف شفت</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 p-4 border-b border-slate-100 md:flex-row md:items-end" data-no-loading>
            <div class="md:w-52">
                <label class="label">النوع</label>
                <select name="report_type" class="input"><option value="">الكل</option>
                    @foreach(\App\Models\ImportBatch::TYPES as $val => $label)<option value="{{ $val }}" @selected(($filters['report_type'] ?? null) === $val)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="md:w-44">
                <label class="label">الحالة</label>
                <select name="status" class="input"><option value="">الكل</option>
                    @foreach(\App\Enums\ImportStatus::options() as $val => $label)<option value="{{ $val }}" @selected(($filters['status'] ?? null) === $val)>{{ $label }}</option>@endforeach
                </select>
            </div>
            @if($all)
                <div class="md:w-48">
                    <label class="label">الموظف</label>
                    <select name="user_id" class="input"><option value="">الكل</option>
                        @foreach($users as $u)<option value="{{ $u->id }}" @selected(($filters['user_id'] ?? null) == $u->id)>{{ $u->name }}</option>@endforeach
                    </select>
                </div>
            @endif
            <div class="flex gap-2"><button class="btn btn-primary">تصفية</button><a href="{{ route('imports.index') }}" class="btn btn-light">مسح</a></div>
        </form>

        @if($batches->isEmpty())
            <x-empty-state title="لا توجد عمليات استيراد" icon="upload"/>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>الرقم</th><th>النوع</th><th>الملف</th><th>رفعه</th><th>التاريخ</th><th>الصفوف</th><th>التقرير</th><th>الحالة</th><th></th></tr></thead>
                    <tbody>
                    @foreach($batches as $b)
                        <tr>
                            <td class="ltr text-right">{{ $b->batch_number }}</td>
                            <td>{{ \App\Models\ImportBatch::TYPES[$b->report_type] ?? $b->report_type }}</td>
                            <td class="text-sm">{{ $b->original_name }}</td>
                            <td>{{ $b->uploader?->name }}</td>
                            <td class="text-xs">{{ $b->uploaded_at?->format('Y/m/d H:i') }}</td>
                            <td class="text-xs tabular-nums">{{ $b->accepted_rows }} مقبول / {{ $b->rejected_rows }} مرفوض</td>
                            <td>@if($b->report)<a class="text-sky-700 underline" href="{{ route('reports.show', $b->report) }}">{{ $b->report->report_number }}</a>@else — @endif</td>
                            <td><x-badge :enum="$b->status"/></td>
                            <td class="text-left whitespace-nowrap">
                                @if($b->report_type !== 'legacy_whatsapp')<a href="{{ route('imports.show', $b) }}" class="btn btn-light btn-sm"><x-icon name="eye" class="size-4"/></a>@endif
                                <a href="{{ route('imports.download', $b) }}" class="btn btn-light btn-sm">تنزيل الأصل</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $batches->links() }}
        @endif
    </div>
</x-layouts.app>
