<x-layouts.app title="التنبيهات">
    <x-page-header title="التنبيهات التلقائية" subtitle="تُفحص القواعد كل ساعة — التنبيه يُغلق تلقائياً عند زوال سببه">
        <x-slot:actions>
            <a href="{{ route('alerts.whatsapp') }}" target="_blank" rel="noopener noreferrer" class="btn btn-accent"><x-icon name="phone" class="size-4"/> ملخص واتساب</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 p-4 border-b border-slate-100 md:flex-row md:flex-wrap md:items-end" data-no-loading>
            <div><label class="label">الحالة</label>
                <select name="status" class="input"><option value="open" @selected($filters['status'] === 'open')>مفتوحة</option><option value="closed" @selected($filters['status'] === 'closed')>مغلقة</option></select></div>
            <div><label class="label">المستوى</label>
                <select name="level" class="input"><option value="">الكل</option>
                    @foreach(\App\Enums\AlertLevel::cases() as $l)<option value="{{ $l->value }}" @selected(($filters['level'] ?? null) === $l->value)>{{ $l->label() }}</option>@endforeach
                </select></div>
            <div><label class="label">القاعدة</label>
                <select name="rule_key" class="input"><option value="">الكل</option>
                    @foreach($rules as $key => $name)<option value="{{ $key }}" @selected(($filters['rule_key'] ?? null) === $key)>{{ $name }}</option>@endforeach
                </select></div>
            <div class="flex gap-2"><button class="btn btn-primary">تصفية</button><a href="{{ route('alerts.index') }}" class="btn btn-light">مسح</a></div>
        </form>

        @if($alerts->isEmpty())
            <x-empty-state title="لا توجد تنبيهات" icon="check-circle">كل شيء على ما يرام.</x-empty-state>
        @else
            <div class="divide-y divide-slate-100">
                @foreach($alerts as $a)
                    <div class="flex flex-wrap items-start gap-3 px-5 py-3 text-sm">
                        <x-badge :enum="$a->level"/>
                        <div class="flex-1 min-w-64">
                            <p class="font-bold text-navy-900">@if($url = $a->subjectUrl())<a href="{{ $url }}" class="hover:underline">{{ $a->title }}</a>@else{{ $a->title }}@endif</p>
                            @if($a->details)<p class="text-slate-600">{{ $a->details }}</p>@endif
                            <p class="text-xs text-slate-400">{{ $rules[$a->rule_key] ?? $a->rule_key }} — ظهر أول مرة {{ $a->first_seen_at->format('Y/m/d H:i') }} — آخر فحص {{ $a->last_seen_at->format('Y/m/d H:i') }}
                                @if($a->status === 'closed') — أُغلق {{ $a->close_reason === 'auto' ? 'تلقائياً' : 'بواسطة '.($a->closer?->name ?? '—') }}@endif</p>
                        </div>
                        @if($canClose && $a->status === 'open')
                            <form method="POST" action="{{ route('alerts.close', $a) }}">@csrf @method('PATCH')
                                <button class="btn btn-light btn-sm">إغلاق</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
            {{ $alerts->links() }}
        @endif
    </div>
</x-layouts.app>
