<x-layouts.app :title="'تقرير التأكيد '.$report->report_number">
    <x-page-header :title="'تقرير تأكيد الطلبيات — '.$report->work_date->format('Y/m/d')" :subtitle="$report->user->name.' — '.$report->report_number">
        <x-slot:actions><x-badge :enum="$report->status"/></x-slot:actions>
    </x-page-header>

    @if($report->rejection_reason)
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><b>سبب الإرجاع:</b> {{ $report->rejection_reason }}</div>
    @endif
    @if($errors->any())
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif

    <form method="POST" action="{{ route('reports.confirmation.update', $report) }}" class="space-y-5"
          x-data="{
              stores: @js(collect($state['stores'])->map(fn ($s) => ['store_id' => (int) $s['store_id'], 'confirmed' => $s['confirmed'] ?? '', 'no_answer' => $s['no_answer'] ?? '', 'cancelled' => array_values($s['cancelled'] ?? [])])->values()),
              options: @js($storeOptions),
              reasons: @js($reasons),
              attempts: @js(array_values($state['attempt_times'] ?? [])),
              pick: '',
              count(t) { return String(t || '').split(/[\s,،;؛]+/).filter(x => x).length },
              name(id) { const o = this.options.find(o => o.id === id); return o ? o.name : '#' + id },
              total(k) { return this.stores.reduce((a, s) => a + (k === 'cancelled' ? s.cancelled.filter(c => c.code).length : this.count(s[k])), 0) },
              add() { const o = this.options.find(o => `${o.name} — ${o.code}` === this.pick || o.name === this.pick);
                  if (o && !this.stores.some(s => s.store_id === o.id)) this.stores.push({ store_id: o.id, confirmed: '', no_answer: '', cancelled: [] }); this.pick = '' },
          }">
        @csrf @method('PUT')
        @if($approveAfter)<input type="hidden" name="approve_after" value="1">@endif

        <section class="card p-5">
            <h2 class="card-title mb-3">ملخص حركة الأوردرات</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 text-center text-sm">
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">الإجمالي</p><p class="text-xl font-bold" x-text="total('confirmed') + total('cancelled') + total('no_answer')"></p></div>
                <div class="rounded-xl bg-green-50 p-3"><p class="text-green-700">المؤكدة</p><p class="text-xl font-bold" x-text="total('confirmed')"></p></div>
                <div class="rounded-xl bg-red-50 p-3"><p class="text-red-700">الملغاة</p><p class="text-xl font-bold" x-text="total('cancelled')"></p></div>
                <div class="rounded-xl bg-amber-50 p-3"><p class="text-amber-700">لا يرد</p><p class="text-xl font-bold" x-text="total('no_answer')"></p></div>
            </div>
        </section>

        <template x-for="(s, i) in stores" :key="s.store_id">
            <section class="card p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-navy-900" x-text="name(s.store_id)"></h3>
                    <button type="button" class="btn btn-ghost btn-sm text-red-600" x-on:click="stores.splice(i, 1)"><x-icon name="trash" class="size-4"/></button>
                </div>
                <input type="hidden" :name="`stores[${i}][store_id]`" :value="s.store_id">
                <div class="grid gap-3 md:grid-cols-2">
                    <div><label class="label">✅ المؤكدة — <span x-text="count(s.confirmed)"></span></label>
                        <textarea class="input ltr" rows="3" :name="`stores[${i}][confirmed]`" x-model="s.confirmed" placeholder="كود في كل سطر"></textarea></div>
                    <div><label class="label">📵 لا يرد — <span x-text="count(s.no_answer)"></span></label>
                        <textarea class="input ltr" rows="3" :name="`stores[${i}][no_answer]`" x-model="s.no_answer" placeholder="كود في كل سطر"></textarea></div>
                </div>
                <div>
                    <p class="label">❌ الملغاة — <span x-text="s.cancelled.filter(c => c.code).length"></span></p>
                    <template x-for="(c, j) in s.cancelled" :key="j">
                        <div class="mb-2 flex flex-wrap gap-2">
                            <input class="input !w-44 ltr" :name="`stores[${i}][cancelled][${j}][code]`" x-model="c.code" placeholder="الكود">
                            <select class="input !w-44" :name="`stores[${i}][cancelled][${j}][reason]`" x-model="c.reason">
                                <option value="">— سبب الإلغاء —</option>
                                <template x-for="r in reasons" :key="r"><option :value="r" x-text="r" :selected="r === c.reason"></option></template>
                            </select>
                            <input x-show="c.reason === 'أخرى'" class="input !w-56" :name="`stores[${i}][cancelled][${j}][other]`" x-model="c.other" placeholder="اكتب السبب">
                            <button type="button" class="btn btn-ghost btn-sm" x-on:click="s.cancelled.splice(j, 1)"><x-icon name="x" class="size-4"/></button>
                        </div>
                    </template>
                    <button type="button" class="btn btn-light btn-sm" x-on:click="s.cancelled.push({ code: '', reason: '', other: '' })">+ طلب ملغى</button>
                </div>
            </section>
        </template>

        <div class="card p-4 flex flex-wrap gap-2">
            <input list="conf-stores" class="input !w-80" x-model="pick" placeholder="+ إضافة متجر (ابحث بالاسم)" x-on:change="add()">
            <datalist id="conf-stores"><template x-for="o in options" :key="o.id"><option :value="o.name + ' — ' + o.code"></option></template></datalist>
        </div>

        <section class="card p-5">
            <h2 class="card-title mb-3">أوقات المحاولات — <span x-text="attempts.length"></span></h2>
            <div class="flex flex-wrap gap-2">
                <template x-for="(t, k) in attempts" :key="k">
                    <div class="flex items-center gap-1">
                        <input type="time" class="input !w-32" :name="`attempt_times[${k}]`" x-model="attempts[k]">
                        <button type="button" class="btn btn-ghost btn-sm" x-on:click="attempts.splice(k, 1)"><x-icon name="x" class="size-4"/></button>
                    </div>
                </template>
                <button type="button" class="btn btn-light btn-sm" x-on:click="attempts.push('')">+ إضافة محاولة</button>
            </div>
            @php $maxA = \App\Services\Reports\ReportDraftService::maxAttempts(); @endphp
            <div x-show="attempts.length > {{ $maxA }}" class="mt-3">
                <p class="text-sm font-semibold text-amber-700">⚠ الحد الأقصى {{ $maxA }} محاولة في الشفت. اذكري سبب التجاوز:</p>
                <input class="input mt-1" name="attempts_over_limit_reason" maxlength="1000" value="{{ old('attempts_over_limit_reason', $report->attempts_over_limit_reason) }}">
            </div>
            @error('attempts_over_limit_reason')<p class="field-error">{{ $message }}</p>@enderror
        </section>

        <section class="card p-5">
            <label class="label">ملاحظة</label>
            <textarea name="notes" rows="3" class="input" maxlength="5000">{{ $state['notes'] }}</textarea>
        </section>

        <div class="flex flex-wrap gap-2">
            <button name="action" value="save" class="btn {{ $approveAfter ? 'btn-accent' : 'btn-light' }}">{{ $approveAfter ? 'حفظ التصحيح واعتماد التقرير' : 'حفظ' }}</button>
            @can('submit', $report)
                <button name="action" value="submit" class="btn btn-accent">حفظ وإرسال (نهاية الشفت)</button>
            @endcan
        </div>
    </form>
    <p class="hint mt-3">أسباب الإلغاء المتاحة: {{ implode('، ', $reasons) }}</p>
</x-layouts.app>
