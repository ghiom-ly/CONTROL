<x-layouts.app title="إرسال لمسؤول المناديب">
    <x-page-header :title="'إرسال لمسؤول مناديب '.$branch->name" subtitle="افتح واتساب، وأرفق السكرينات من جهازك قبل ما تضغط إرسال">
        <x-slot:actions><a href="{{ route('open-codes.index', ['tab' => 'courier']) }}" class="btn btn-light">رجوع للأكواد</a></x-slot:actions>
    </x-page-header>

    @unless($hasPhone)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-800">
            رقم مسؤول المناديب غير مسجل لهذا الفرع، فواتساب بيفتح وتختار المحادثة بيدك.
            @can('settings.manage')<a href="{{ route('settings.branches') }}" class="underline">سجّل الرقم من الإعدادات ← الفروع</a>@endcan
        </div>
    @endunless

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card p-5" x-data="{ done: false }">
            <h2 class="card-title mb-3">نص الرسالة</h2>
            <textarea x-ref="t" rows="14" class="input" readonly>{{ $text }}</textarea>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ $waUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-accent"><x-icon name="phone" class="size-4"/> فتح واتساب{{ $branch->couriers_manager_name ? ' — '.$branch->couriers_manager_name : '' }}</a>
                <button type="button" class="btn btn-light" x-on:click="navigator.clipboard.writeText($refs.t.value); done = true; setTimeout(() => done = false, 2000)">
                    <span x-text="done ? 'تم النسخ ✓' : 'نسخ النص'">نسخ النص</span>
                </button>
            </div>
            @unless(\App\Support\WhatsAppText::fits($text))
                <p class="mt-2 text-xs text-slate-500">الرسالة طويلة على رابط واتساب: انسخ النص والصقه في المحادثة.</p>
            @endunless
        </section>

        <section class="card p-5" x-data="branchScreens(@js($screens->map(fn ($a) => ['url' => route('attachments.show', $a), 'name' => $a->original_name])->values()))">
            <h2 class="card-title mb-3">سكرينات المندوب ({{ $screens->count() }})</h2>
            @if($screens->isEmpty())
                <p class="text-sm text-slate-500">ما فيش سكرينات مرفوعة في تقارير هذه الأكواد.</p>
            @else
                <div class="flex flex-wrap gap-3">
                    @foreach($screens as $a)
                        <a href="{{ route('attachments.show', $a) }}" download="{{ $a->original_name }}" target="_blank">
                            <img src="{{ route('attachments.show', $a) }}" alt="{{ $a->original_name }}" class="h-24 w-24 rounded-lg border object-cover">
                        </a>
                    @endforeach
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="btn btn-primary" x-show="canShare" x-on:click="share()" :disabled="busy">
                        <span x-text="busy ? 'جاري التجهيز…' : 'مشاركة السكرينات (واتساب)'"></span>
                    </button>
                    <button type="button" class="btn btn-light" x-on:click="downloadAll()">تنزيل كل السكرينات</button>
                </div>
                <p class="mt-2 text-xs text-slate-500">من الموبايل: «مشاركة السكرينات» ترسلها مع النص لواتساب وتختار مسؤول المناديب. من الكمبيوتر: نزّلها وأرفقها في المحادثة.</p>
                <p class="field-error" x-show="error" x-text="error"></p>
            @endif
        </section>
    </div>

    <script>
        function branchScreens(items) {
            return {
                busy: false, error: '',
                canShare: !!(navigator.canShare && navigator.canShare({ files: [new File([''], 'a.png', { type: 'image/png' })] })),
                async files() {
                    return Promise.all(items.map(async (it) => {
                        const blob = await (await fetch(it.url, { credentials: 'same-origin' })).blob();
                        return new File([blob], it.name || 'screen.png', { type: blob.type || 'image/png' });
                    }));
                },
                async share() {
                    this.busy = true; this.error = '';
                    try {
                        await navigator.share({ files: await this.files(), text: @js($text) });
                    } catch (e) {
                        if (e.name !== 'AbortError') this.error = 'ما قدرناش نشاركوا السكرينات — نزّلها وأرفقها بيدك.';
                    }
                    this.busy = false;
                },
                downloadAll() {
                    items.forEach((it, i) => setTimeout(() => {
                        const a = document.createElement('a');
                        a.href = it.url; a.download = it.name || 'screen.png';
                        document.body.appendChild(a); a.click(); a.remove();
                    }, i * 300));
                },
            };
        }
    </script>
</x-layouts.app>
