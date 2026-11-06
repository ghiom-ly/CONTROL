<x-layouts.app title="إدارة المشكلات">
    <x-page-header title="إدارة المشكلات" subtitle="المشكلات المبلّغ عنها من التقارير أو المفتوحة من صفحات المتاجر"/>

    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-5 sm:max-w-4xl">
        @foreach(\App\Enums\IssueStatus::cases() as $st)
            <a href="{{ route('issues.index', ['status' => $st->value]) }}"><x-stat-card :label="$st->label()" :value="(int) ($counts[$st->value] ?? 0)" icon="alert" :tone="$st->color()" class="!p-4"/></a>
        @endforeach
        <a href="{{ route('issues.index', ['overdue' => 1]) }}"><x-stat-card label="متأخرة" :value="$overdueCount" icon="clock" tone="red" class="!p-4"/></a>
    </div>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 p-4 border-b border-slate-100 md:flex-row md:flex-wrap md:items-end" data-no-loading>
            @foreach(['status' => [\App\Enums\IssueStatus::class, 'الحالة'], 'priority' => [\App\Enums\IssuePriority::class, 'الأولوية'], 'type' => [\App\Enums\IssueType::class, 'النوع']] as $field => [$enum, $label])
                <div><label class="label">{{ $label }}</label>
                    <select name="{{ $field }}" class="input"><option value="">الكل</option>
                        @foreach($enum::cases() as $c)<option value="{{ $c->value }}" @selected(($filters[$field] ?? null) === $c->value)>{{ $c->label() }}</option>@endforeach
                    </select></div>
            @endforeach
            <div><label class="label">المسؤول</label>
                <select name="assigned_to" class="input"><option value="">الكل</option>
                    @foreach($managers as $m)<option value="{{ $m->id }}" @selected(($filters['assigned_to'] ?? null) == $m->id)>{{ $m->name }}</option>@endforeach
                </select></div>
            <label class="flex items-center gap-1 text-sm"><input type="checkbox" name="overdue" value="1" @checked($filters['overdue'] ?? false) class="size-4"> المتأخرة فقط</label>
            <div class="flex gap-2"><button class="btn btn-primary">تصفية</button><a href="{{ route('issues.index') }}" class="btn btn-light">مسح</a></div>
        </form>

        @if($issues->isEmpty())
            <x-empty-state title="لا توجد مشكلات مطابقة" icon="alert">المشكلات المبلّغ عنها في التقارير تظهر هنا بعد اعتماد التقرير.</x-empty-state>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>الرقم</th><th>العنوان</th><th>المتجر</th><th>النوع</th><th>الأولوية</th><th>الحالة</th><th>المسؤول</th><th>المهلة</th></tr></thead>
                <tbody>
                @foreach($issues as $i)
                    <tr class="{{ $i->isOverdue() ? 'bg-red-50/60' : '' }}">
                        <td class="ltr text-right"><a class="text-navy-700 underline" href="{{ route('issues.show', $i) }}">{{ $i->issue_number }}</a></td>
                        <td class="font-bold"><a href="{{ route('issues.show', $i) }}">{{ $i->title }}</a></td>
                        <td>{{ $i->store?->name ?? '—' }}</td>
                        <td class="text-sm">{{ $i->type->label() }}</td>
                        <td><x-badge :enum="$i->priority"/></td>
                        <td><x-badge :enum="$i->status"/> @if($i->isOverdue())<span class="badge badge-red">متأخرة</span>@endif</td>
                        <td>{{ $i->assignee?->name ?? '—' }}</td>
                        <td class="tabular-nums text-sm">{{ $i->due_at?->format('Y/m/d H:i') ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            {{ $issues->links() }}
        @endif
    </div>
</x-layouts.app>
