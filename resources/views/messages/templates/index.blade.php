<x-layouts.app title="قوالب رسائل المتاجر">
    <x-page-header title="قوالب رسائل المتاجر" subtitle="المسؤول يكتب القالب، والمدير العام يعتمده — لا يُرسل إلا المعتمد">
        <x-slot:actions><a href="{{ route('messages.templates.create') }}" class="btn btn-accent"><x-icon name="plus" class="size-4"/> قالب جديد</a></x-slot:actions>
    </x-page-header>
    @include('messages._tabs')

    <div class="grid gap-4 lg:grid-cols-2">
        @forelse($templates as $t)
            @php [$label, $color] = \App\Models\MessageTemplate::STATUSES[$t->status]; @endphp
            <div class="card p-5 space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="card-title">{{ $t->name }}</h3>
                    <span class="badge badge-{{ $color }}">{{ $label }}</span>
                    <span class="badge badge-navy">{{ \App\Models\MessageTemplate::TYPES[$t->type] }}</span>
                    @if($t->version)<span class="text-xs text-slate-400">النسخة {{ $t->version }}@if($t->approver) — اعتمدها {{ $t->approver->name }}@endif</span>@endif
                </div>
                <pre class="whitespace-pre-wrap rounded-lg bg-slate-50 p-3 font-sans text-sm leading-7">{{ $t->body }}</pre>
                @if($t->status === 'rejected' && $t->review_note)<p class="text-sm text-red-700"><b>ملاحظة المدير:</b> {{ $t->review_note }}</p>@endif
                <div class="flex flex-wrap gap-2" x-data="{ rej: false }">
                    <a href="{{ route('messages.templates.edit', $t) }}" class="btn btn-light btn-sm"><x-icon name="pencil" class="size-4"/> تعديل</a>
                    @if(in_array($t->status, ['draft', 'rejected']))
                        <form method="POST" action="{{ route('messages.templates.submit', $t) }}">@csrf<button class="btn btn-primary btn-sm">إرسال للاعتماد</button></form>
                        <form method="POST" action="{{ route('messages.templates.destroy', $t) }}">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm text-red-600">حذف</button></form>
                    @endif
                    @can('messages.approve')
                        @if($t->status === 'pending')
                            <form method="POST" action="{{ route('messages.templates.approve', $t) }}">@csrf<button class="btn btn-accent btn-sm">اعتماد</button></form>
                            <button type="button" class="btn btn-light btn-sm" x-on:click="rej = !rej">إرجاع</button>
                        @elseif($t->status === 'approved')
                            <form method="POST" action="{{ route('messages.templates.disable', $t) }}">@csrf<button class="btn btn-light btn-sm">إيقاف</button></form>
                        @elseif($t->status === 'disabled')
                            <form method="POST" action="{{ route('messages.templates.enable', $t) }}">@csrf<button class="btn btn-light btn-sm">إعادة تفعيل</button></form>
                        @endif
                        <form x-show="rej" method="POST" action="{{ route('messages.templates.reject', $t) }}" class="flex w-full gap-2">@csrf
                            <input name="review_note" class="input" placeholder="ملاحظة للمسؤول" required maxlength="1000">
                            <button class="btn btn-danger btn-sm">إرجاع</button>
                        </form>
                    @endcan
                </div>
            </div>
        @empty
            <div class="card"><x-empty-state title="لا توجد قوالب" icon="report"/></div>
        @endforelse
    </div>
    @error('status')<p class="field-error mt-3">{{ $message }}</p>@enderror
    @error('review_note')<p class="field-error mt-3">{{ $message }}</p>@enderror
</x-layouts.app>
