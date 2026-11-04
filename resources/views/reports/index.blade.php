<x-layouts.app title="التقارير اليومية">
    <x-page-header title="التقارير اليومية" :subtitle="$all ? 'تقارير جميع الموظفين' : 'تقاريري'">
        <x-slot:actions>
            @if($openDraft)
                <a href="{{ route('reports.edit', $openDraft) }}" class="btn btn-accent"><x-icon name="pencil" class="size-4"/> متابعة الشفت المفتوح</a>
            @else
                @can('create', \App\Models\DailyReport::class)
                    <a href="{{ route('reports.create') }}" class="btn btn-accent"><x-icon name="plus" class="size-4"/> بدء شفت جديد</a>
                @endcan
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 p-4 border-b border-slate-100 md:flex-row md:items-end" data-no-loading>
            @if($all)
                <div class="md:w-48">
                    <label class="label">الموظف</label>
                    <select name="user_id" class="input"><option value="">الكل</option>
                        @foreach($users as $u)<option value="{{ $u->id }}" @selected(($filters['user_id'] ?? null) == $u->id)>{{ $u->name }}</option>@endforeach
                    </select>
                </div>
            @endif
            <div class="md:w-40">
                <label class="label">الحالة</label>
                <select name="status" class="input"><option value="">الكل</option>
                    @foreach(\App\Enums\ReportStatus::options() as $val => $label)<option value="{{ $val }}" @selected(($filters['status'] ?? null) === $val)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div><label class="label">من</label><input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="input"></div>
            <div><label class="label">إلى</label><input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="input"></div>
            <div class="flex gap-2"><button class="btn btn-primary">تصفية</button><a href="{{ route('reports.index') }}" class="btn btn-light">مسح</a></div>
        </form>

        @if($reports->isEmpty())
            <x-empty-state title="لا توجد تقارير" icon="report"/>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>التقرير</th>@if($all)<th>الموظف</th>@endif<th>تاريخ العمل</th><th>الحالة</th><th>المتاجر</th><th>الأوردرات</th><th>التسليم</th><th></th></tr></thead>
                    <tbody>
                    @foreach($reports as $r)
                        <tr>
                            <td class="ltr text-right">{{ $r->report_number }}</td>
                            @if($all)<td class="font-bold">{{ $r->user->name }}</td>@endif
                            <td>{{ $r->work_date->format('Y/m/d') }}</td>
                            <td><x-badge :enum="$r->status"/></td>
                            <td class="tabular-nums">{{ $r->stores_count }}</td>
                            <td class="tabular-nums">{{ $r->total_shipments ?? 'غير متوفر' }}</td>
                            <td class="text-xs tabular-nums">{{ $r->start_delivery_rate ?? '—' }}% ← {{ $r->end_delivery_rate ?? '—' }}%</td>
                            <td class="text-left whitespace-nowrap">
                                <a href="{{ route('reports.show', $r) }}" class="btn btn-light btn-sm"><x-icon name="eye" class="size-4"/> عرض</a>
                                @can('update', $r)
                                    <a href="{{ route('reports.edit', $r) }}" class="btn btn-light btn-sm"><x-icon name="pencil" class="size-4"/></a>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $reports->links() }}
        @endif
    </div>
</x-layouts.app>
