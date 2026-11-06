<x-layouts.app :title="'المشكلة '.$issue->issue_number">
    <x-page-header :title="$issue->title" :subtitle="$issue->issue_number.' — '.($issue->store?->name ?? 'بلا متجر')">
        <x-slot:actions>
            <x-badge :enum="$issue->status"/> <x-badge :enum="$issue->priority"/>
            @if($issue->isOverdue())<span class="badge badge-red">متأخرة</span>@endif
            <a href="{{ route('issues.index') }}" class="btn btn-light">رجوع للقائمة</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card p-5 text-sm space-y-2">
                <p><b>النوع:</b> {{ $issue->type->label() }}</p>
                <p class="whitespace-pre-wrap"><b>الوصف:</b> {{ $issue->description }}</p>
                @if($issue->action_taken)<p class="whitespace-pre-wrap"><b>الإجراء المتخذ:</b> {{ $issue->action_taken }}</p>@endif
            </section>

            @can('update', $issue)
                <section class="card p-5">
                    <h2 class="card-title mb-3">إضافة تحديث</h2>
                    <form method="POST" action="{{ route('issues.updates.store', $issue) }}" class="grid gap-3 sm:grid-cols-3">
                        @csrf
                        <div><label class="label">الحالة</label>
                            <select name="status" class="input">@foreach(\App\Enums\IssueStatus::cases() as $s)<option value="{{ $s->value }}" @selected($issue->status === $s)>{{ $s->label() }}</option>@endforeach</select></div>
                        <div><label class="label">الأولوية</label>
                            <select name="priority" class="input">@foreach(\App\Enums\IssuePriority::cases() as $p)<option value="{{ $p->value }}" @selected($issue->priority === $p)>{{ $p->label() }}</option>@endforeach</select></div>
                        <div><label class="label">إسناد إلى</label>
                            <select name="assigned_to" class="input"><option value="">— بدون —</option>
                                @foreach($managers as $m)<option value="{{ $m->id }}" @selected($issue->assigned_to === $m->id)>{{ $m->name }}</option>@endforeach
                            </select>@error('assigned_to')<p class="field-error">{{ $message }}</p>@enderror</div>
                        <div class="sm:col-span-3"><label class="label">تعليق</label><textarea name="comment" rows="2" class="input" maxlength="5000">{{ old('comment') }}</textarea></div>
                        <div class="sm:col-span-3"><label class="label">الإجراء المتخذ (مطلوب عند الحل أو الإغلاق)</label><textarea name="action_taken" rows="2" class="input" maxlength="5000">{{ old('action_taken', $issue->action_taken) }}</textarea>
                            @error('action_taken')<p class="field-error">{{ $message }}</p>@enderror</div>
                        <div class="sm:col-span-3"><button class="btn btn-accent">حفظ التحديث</button></div>
                    </form>
                </section>
            @endcan

            <section class="card">
                <div class="card-header"><h2 class="card-title">سجل التحديثات</h2><span class="badge badge-gray">{{ $issue->updates->count() }}</span></div>
                <div class="divide-y divide-slate-100 text-sm">
                    @forelse($issue->updates as $u)
                        <div class="px-5 py-3">
                            <p class="text-xs text-slate-500"><span class="tabular-nums">{{ $u->created_at->format('Y/m/d H:i') }}</span> — {{ $u->user?->name }}</p>
                            @if($u->new_status)<p>الحالة: {{ \App\Enums\IssueStatus::tryFrom((string) $u->old_status)?->label() }} ← <b>{{ \App\Enums\IssueStatus::tryFrom($u->new_status)?->label() }}</b></p>@endif
                            @if($u->new_assignee_id)<p>أُسندت إلى: <b>{{ $u->newAssignee?->name }}</b></p>@endif
                            @if($u->comment)<p class="whitespace-pre-wrap">{{ $u->comment }}</p>@endif
                        </div>
                    @empty
                        <p class="px-5 py-3 text-slate-500">لا توجد تحديثات بعد.</p>
                    @endforelse
                </div>
            </section>
        </div>

        <aside class="card p-5 text-sm space-y-2 h-fit">
            <p><b>المتجر:</b> @if($issue->store)<a class="text-navy-700 underline" href="{{ route('stores.show', $issue->store) }}">{{ $issue->store->name }}</a>@else — @endif</p>
            <p><b>الكود:</b> @if($issue->shipment)<x-code :code="$issue->shipment->code"/>@else — @endif</p>
            <p><b>التقرير:</b> @if($issue->report)<a class="text-navy-700 underline" href="{{ route('reports.show', $issue->report) }}">{{ $issue->report->report_number }}</a>@else — @endif</p>
            <p><b>المبلِّغ:</b> {{ $issue->reporter?->name }}</p>
            <p><b>تاريخ التبليغ:</b> <span class="tabular-nums">{{ $issue->reported_at?->format('Y/m/d H:i') }}</span></p>
            <p><b>المهلة:</b> <span class="tabular-nums">{{ $issue->due_at?->format('Y/m/d H:i') ?? '—' }}</span></p>
            <p><b>المسؤول:</b> {{ $issue->assignee?->name ?? 'غير مسندة' }}</p>
            @if($issue->resolved_at)<p><b>تاريخ الحل:</b> <span class="tabular-nums">{{ $issue->resolved_at->format('Y/m/d H:i') }}</span></p>@endif
            @if($issue->closed_at)<p><b>تاريخ الإغلاق:</b> <span class="tabular-nums">{{ $issue->closed_at->format('Y/m/d H:i') }}</span></p>@endif
        </aside>
    </div>
</x-layouts.app>
