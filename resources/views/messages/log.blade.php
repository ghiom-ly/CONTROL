<x-layouts.app title="سجل الرسائل">
    <x-page-header title="سجل الرسائل" subtitle="كل رسالة فُتحت للإرسال عبر واتساب"/>
    @include('messages._tabs')

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 p-4 border-b border-slate-100 md:flex-row md:items-end" data-no-loading>
            @foreach(['store_id' => ['المتجر', $stores], 'template_id' => ['القالب', $templates], 'sent_by' => ['المرسل', $senders]] as $k => [$label, $list])
                <div class="md:w-48">
                    <label class="label">{{ $label }}</label>
                    <select name="{{ $k }}" class="input"><option value="">الكل</option>
                        @foreach($list as $o)<option value="{{ $o->id }}" @selected(($filters[$k] ?? null) == $o->id)>{{ $o->name }}</option>@endforeach
                    </select>
                </div>
            @endforeach
            <div><label class="label">من</label><input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="input"></div>
            <div><label class="label">إلى</label><input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="input"></div>
            <div class="flex gap-2"><button class="btn btn-primary">تصفية</button><a href="{{ route('messages.log') }}" class="btn btn-light">مسح</a></div>
        </form>
        @if($messages->isEmpty())
            <x-empty-state title="لا توجد رسائل" icon="phone"/>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>الوقت</th><th>المتجر</th><th>القالب</th><th>المرسل</th><th>النص</th></tr></thead>
                    <tbody>
                    @foreach($messages as $m)
                        <tr>
                            <td class="text-xs whitespace-nowrap">{{ $m->created_at->format('Y/m/d H:i') }}</td>
                            <td class="font-bold"><a class="text-sky-700" href="{{ route('stores.show', $m->store_id) }}">{{ $m->store?->name }}</a></td>
                            <td class="text-sm">{{ $m->template?->name ?? '—' }}</td>
                            <td class="text-sm">{{ $m->sender?->name }}</td>
                            <td class="text-sm whitespace-pre-wrap">{{ \Illuminate\Support\Str::limit($m->body, 200) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $messages->links() }}
        @endif
    </div>
</x-layouts.app>
