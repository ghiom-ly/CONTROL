<x-layouts.app title="سجل العمليات">
    <x-page-header title="سجل العمليات" subtitle="كل العمليات المهمة مسجلة مع المستخدم والوقت والقيم قبل وبعد التعديل">
        <x-slot:actions><x-period-filter :period="$period" :keep="$filters"/></x-slot:actions>
    </x-page-header>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 p-4 border-b border-slate-100 md:flex-row md:items-end" data-no-loading>
            @foreach($period->query() as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <div class="md:w-56">
                <label class="label">المستخدم</label>
                <select name="user_id" class="input"><option value="">الكل</option>
                    @foreach($users as $u)<option value="{{ $u->id }}" @selected(($filters['user_id'] ?? null) == $u->id)>{{ $u->name }}</option>@endforeach
                </select>
            </div>
            <div class="md:w-48">
                <label class="label">نوع العملية</label>
                <select name="event" class="input"><option value="">الكل</option>
                    @foreach(\App\Models\AuditLog::EVENT_LABELS as $k => $l)<option value="{{ $k }}" @selected(($filters['event'] ?? null) === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div class="md:w-48">
                <label class="label">السجل</label>
                <select name="model" class="input"><option value="">الكل</option>
                    @foreach(\App\Models\AuditLog::MODEL_LABELS as $k => $l)<option value="{{ $k }}" @selected(($filters['model'] ?? null) === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div class="flex gap-2"><button class="btn btn-primary">تصفية</button><a href="{{ route('audit.index') }}" class="btn btn-light">مسح</a></div>
        </form>

        @if($logs->isEmpty())
            <x-empty-state title="لا توجد عمليات في هذه الفترة" icon="history"/>
        @else
            <div class="divide-y divide-slate-100">
                @foreach($logs as $log)
                    <div x-data="{ open: false }" class="px-5 py-3">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <span class="badge {{ in_array($log->event, ['deleted','login_failed','rejected']) ? 'badge-red' : (in_array($log->event, ['created','approved']) ? 'badge-green' : 'badge-navy') }}">{{ $log->eventLabel() }}</span>
                            <span class="font-semibold text-slate-700">{{ $log->user?->name ?? 'النظام' }}</span>
                            @if($log->modelLabel())<span class="text-slate-500">{{ $log->modelLabel() }} #{{ $log->auditable_id }}</span>@endif
                            @if($log->description)<span class="text-slate-500">{{ $log->description }}</span>@endif
                            <span class="ms-auto text-xs text-slate-400 whitespace-nowrap">{{ $log->created_at->format('Y/m/d H:i:s') }} · <span class="ltr inline-block">{{ $log->ip_address }}</span></span>
                            @if($log->old_values || $log->new_values)
                                <button type="button" class="text-xs font-bold text-brand-600" x-on:click="open = !open" x-text="open ? 'إخفاء التفاصيل' : 'التفاصيل'"></button>
                            @endif
                        </div>
                        @if($log->old_values || $log->new_values)
                            <div x-show="open" x-cloak class="mt-3 overflow-x-auto rounded-xl bg-slate-50 p-3">
                                <table class="w-full text-xs">
                                    <thead><tr class="text-slate-500"><th class="p-1 text-right">الحقل</th><th class="p-1 text-right">قبل</th><th class="p-1 text-right">بعد</th></tr></thead>
                                    <tbody>
                                    @foreach(array_unique(array_merge(array_keys($log->old_values ?? []), array_keys($log->new_values ?? []))) as $field)
                                        <tr class="border-t border-slate-200">
                                            <td class="p-1 font-semibold ltr text-right">{{ $field }}</td>
                                            <td class="p-1 text-red-700 break-all">{{ is_array($v = $log->old_values[$field] ?? null) ? json_encode($v, JSON_UNESCAPED_UNICODE) : ($v ?? '—') }}</td>
                                            <td class="p-1 text-emerald-700 break-all">{{ is_array($v = $log->new_values[$field] ?? null) ? json_encode($v, JSON_UNESCAPED_UNICODE) : ($v ?? '—') }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            {{ $logs->links() }}
        @endif
    </div>
</x-layouts.app>
