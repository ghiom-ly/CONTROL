<x-layouts.app :title="'تقرير '.$report->report_number">
    <x-page-header :title="'ريبورت تاريخ '.$report->work_date->format('Y-m-d')"
                   :subtitle="'الموظف: '.$report->user->name.' — '.$report->report_number.' — '.$report->status->label()">
        @if(Route::has('reports.show'))
            <x-slot:actions>
                @if($report->user_id === auth()->id() && auth()->user()->can('submit', $report))
                    <a href="#submit-bar" class="btn btn-accent">إنهاء الشفت والإرسال</a>
                @endif
                <a href="{{ route('reports.show', $report) }}" class="btn btn-light"><x-icon name="eye" class="size-4"/> عرض</a>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if($report->rejection_reason && $report->status === \App\Enums\ReportStatus::Rejected)
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <p class="font-bold">أُرجع التقرير للتعديل:</p><p>{{ $report->rejection_reason }}</p>
        </div>
    @endif

    <form method="POST" action="{{ route('reports.update', $report) }}" id="report-form" enctype="multipart/form-data"
          x-data="reportForm(@js($formState), @js($storeOptions), @js($resultOptions), {{ $maxAttempts }})" class="space-y-5"
          x-on:input="dirty = true" x-on:change="dirty = true" x-on:submit="dirty = false"
          x-init="window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } })">
        @csrf
        @method('PUT')
        @if(request('approve_after')) <input type="hidden" name="approve_after" value="1"> @endif

        {{-- ⓪ بداية الشفت --}}
        <section class="card p-5">
            <h2 class="card-title mb-3">⓪ بداية الشفت — طلبيات ونسبة تسليم كل متجر</h2>
            <div class="table-wrap mb-3">
                <table class="table">
                    <thead><tr><th>المتجر</th><th>طلبيات البداية</th><th>نسبة التسليم %</th></tr></thead>
                    <tbody>
                    <template x-for="(s, i) in stores" :key="'st' + s.store_id">
                        <tr>
                            <td class="font-bold" x-text="storeName(s.store_id)"></td>
                            <td><input class="input !w-28" inputmode="numeric" :name="`stores[${i}][start_orders]`" x-model="s.start_orders">
                                <span x-show="s.start_source === 'file'" class="badge badge-blue">من الملف</span></td>
                            <td><input class="input !w-28" inputmode="decimal" :name="`stores[${i}][start_rate]`" x-model="s.start_rate"></td>
                        </tr>
                    </template>
                    </tbody>
                </table>
            </div>
            <div class="grid gap-3 sm:grid-cols-3 text-sm">
                <div><p class="label">إجمالي الطلبيات (محسوب)</p><p class="font-bold tabular-nums" x-text="startTotals().total ?? 'غير متوفر'"></p></div>
                <div><p class="label">نسبة التسليم العامة (محسوبة)</p><p class="font-bold tabular-nums" x-text="startTotals().rate === null ? 'غير متوفر' : startTotals().rate + '%'"></p></div>
                <div><p class="label">بدأ الشفت (الحضور)</p><p class="font-bold">{{ $report->shift_started_at?->format('Y/m/d H:i') ?? '—' }}</p></div>
            </div>
            @foreach($errors->get('stores.*.start_*') as $msgs) @foreach($msgs as $m)<p class="field-error">{{ $m }}</p>@endforeach @endforeach
        </section>

        {{-- ① ملخص حركة الأوردرات --}}
        <section class="card p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h2 class="card-title">① ملخص حركة الأوردرات</h2>
                <p class="text-sm text-slate-600">عدد المتاجر: <b x-text="stores.length"></b> — الإجمالي: <b x-text="totalOrders()"></b></p>
            </div>
            @error('codes')<p class="field-error mb-2">{{ $message }}</p>@enderror

            <template x-for="(s, i) in stores" :key="s.store_id">
                <div class="mb-3 rounded-xl border border-slate-200" :class="s.open && 'bg-slate-50/60'">
                    <div class="flex flex-wrap items-center gap-3 p-3">
                        <button type="button" class="font-bold text-navy-900" x-on:click="s.open = !s.open">
                            <span x-text="s.open ? '▼' : '►'"></span> <span x-text="storeName(s.store_id)"></span>
                        </button>
                        <span x-show="!isMine(s.store_id)" class="badge badge-yellow">خارج متاجري</span>
                        <label class="text-sm text-slate-600">الأوردرات التي اشتغلت عليها في هذا الشفت:</label>
                        <input type="text" inputmode="numeric" class="input !w-24" :name="`stores[${i}][shipments_count]`" x-model="s.shipments_count">
                        <span class="text-xs text-slate-400" x-text="`${s.codes.length} كود`"></span>
                        <button type="button" class="btn btn-ghost btn-sm ms-auto" x-on:click="removeStore(i)"><x-icon name="trash" class="size-4"/></button>
                        <input type="hidden" :name="`stores[${i}][store_id]`" :value="s.store_id">
                    </div>
                    <div class="border-t border-emerald-100 bg-emerald-50/50 p-3">
                        <label class="label">الأكواد المسلّمة بعد المتابعة — <span x-text="deliveredCodes(s).length"></span> كود</label>
                        <div class="flex flex-wrap items-start gap-2">
                            <textarea class="input !w-72 ltr" rows="1" x-model="s.deliveredPaste" x-on:change="addDelivered(s)" placeholder="اكتب أو الصق الأكواد المسلّمة"></textarea>
                            <button type="button" class="btn btn-light btn-sm" x-on:click="addDelivered(s)">إضافة</button>
                        </div>
                        <p x-show="deliveredCodes(s).length" class="mt-2 text-xs text-emerald-800 ltr" x-text="deliveredCodes(s).join('  ')"></p>
                    </div>
                    <div x-show="s.open" class="border-t border-slate-200 p-3 space-y-2">
                        <p class="label">أكواد تابعتها في هذا المتجر (اختياري) ونتيجة كل كود</p>
                        <template x-for="(c, j) in s.codes" :key="j">
                            <div class="flex flex-wrap gap-2">
                                <input class="input !w-40 ltr" :name="`stores[${i}][codes][${j}][code]`" x-model="c.code" placeholder="الكود">
                                <select class="input !w-auto" :name="`stores[${i}][codes][${j}][result]`" x-model="c.result">
                                    <template x-for="(label, val) in results" :key="val"><option :value="val" x-text="label" :selected="val === c.result"></option></template>
                                </select>
                                <button type="button" class="btn btn-ghost btn-sm" x-on:click="s.codes.splice(j, 1)"><x-icon name="x" class="size-4"/></button>
                            </div>
                        </template>
                        <div class="flex flex-wrap items-start gap-2">
                            <button type="button" class="btn btn-light btn-sm" x-on:click="s.codes.push({code: '', result: 'courier_promised_delivery'})">+ إضافة كود</button>
                            <textarea class="input !w-56" rows="1" x-model="s.paste" placeholder="لصق عدة أكواد"></textarea>
                            <select class="input !w-auto" x-model="s.pasteResult">
                                <template x-for="(label, val) in results" :key="val"><option :value="val" x-text="label"></option></template>
                            </select>
                            <button type="button" class="btn btn-light btn-sm" x-on:click="pasteInto(s)">إضافة الملصوق</button>
                        </div>
                        <div>
                            <label class="label">ملاحظة عن المتجر</label>
                            <input class="input" :name="`stores[${i}][notes]`" x-model="s.notes" maxlength="2000">
                        </div>
                    </div>
                </div>
            </template>

            <div class="flex flex-wrap gap-2">
                <input list="store-options" class="input !w-72" x-model="storeSearch" placeholder="+ إضافة متجر (ابحث بالاسم)" x-on:change="addStoreFromSearch()">
                <datalist id="store-options">
                    <template x-for="o in availableStores()" :key="o.id"><option :value="o.name + ' — ' + o.code" x-text="o.mine ? 'متاجري' : ''"></option></template>
                </datalist>
            </div>
            @error('stores')<p class="field-error">{{ $message }}</p>@enderror
            @foreach($errors->get('stores.*') as $msgs) @foreach($msgs as $m)<p class="field-error">{{ $m }}</p>@endforeach @endforeach
        </section>

        {{-- ② أوقات المحاولات --}}
        <section class="card p-5">
            <h2 class="card-title mb-3">② أوقات المحاولات — <span x-text="attempts.length"></span> محاولة بالمجمل</h2>
            <div class="flex flex-wrap gap-2">
                <template x-for="(t, k) in attempts" :key="k">
                    <div class="flex items-center gap-1">
                        <span class="text-sm text-slate-500" x-text="`المحاولة ${k + 1}:`"></span>
                        <input type="time" class="input !w-32" :name="`attempt_times[${k}]`" x-model="attempts[k]">
                        <button type="button" class="btn btn-ghost btn-sm" x-on:click="attempts.splice(k, 1)"><x-icon name="x" class="size-4"/></button>
                    </div>
                </template>
                <button type="button" class="btn btn-light btn-sm" x-on:click="attempts.push('')">+ إضافة محاولة</button>
            </div>
            <div x-show="attempts.length > maxAttempts" class="mt-3">
                <p class="text-sm font-semibold text-amber-700">⚠ الحد الأقصى {{ $maxAttempts }} محاولة في الشفت. اذكر سبب التجاوز (سيظهر للمسؤول):</p>
                <input class="input mt-1" name="attempts_over_limit_reason" x-model="overLimitReason" maxlength="1000">
            </div>
            @error('attempts_over_limit_reason')<p class="field-error">{{ $message }}</p>@enderror
            @error('attempt_times.*')<p class="field-error">{{ $message }}</p>@enderror
        </section>

        {{-- ③ الأكواد غير المُجاب عليها --}}
        <section class="card p-5 space-y-4">
            <h2 class="card-title">③ الأكواد غير المُجاب عليها</h2>
            <p class="hint">اكتب أو الصق أكواد كل متجر في خانته — كود في كل سطر أو مفصولة بمسافة.</p>
            <p x-show="stores.length === 0" class="text-sm text-slate-500">أضف المتاجر في القسم ① أولاً.</p>
            <template x-for="s in stores" :key="'na' + s.store_id">
                <div class="rounded-xl border border-slate-200 p-3">
                    <p class="font-bold text-navy-900 mb-2" x-text="storeName(s.store_id)"></p>
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach(['customer_no_answer' => 'أكواد لم يرد عليها الزبون (لتحويلها للمتجر)', 'courier_no_answer' => 'أكواد لم يرد عليها المندوب أو لا تستجيب ولا تُحل'] as $result => $title)
                            <div>
                                <label class="label">{{ $title }} — <span x-text="s.codes.filter(c => c.result === '{{ $result }}' && c.code).length"></span></label>
                                <textarea class="input ltr" rows="3" placeholder="مثال: A123 B456"
                                          :value="bucketText(s, '{{ $result }}')"
                                          x-on:change="setBucket(s, '{{ $result }}', $event.target.value)"></textarea>
                            </div>
                        @endforeach
                    </div>
                </div>
            </template>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach(\App\Models\Attachment::REPORT_CATEGORIES as $cat => $catLabel)
                    <div>
                        <label class="label">رفع {{ $catLabel }}</label>
                        <input type="file" name="screens[{{ $cat }}][]" accept="image/png,image/jpeg,image/webp" multiple class="text-sm">
                    </div>
                @endforeach
            </div>
            <p class="hint">تُرفع الصور مع «حفظ مسودة» أو «حفظ وإرسال» — الصور المرفوعة سابقاً تظهر أسفل الصفحة.</p>
            @error('screens.*')<p class="field-error">{{ $message }}</p>@enderror
            @foreach($errors->get('screens.*.*') as $msgs) @foreach($msgs as $m)<p class="field-error">{{ $m }}</p>@endforeach @endforeach
        </section>

        {{-- ④ ملاحظات واستفسارات --}}
        <section class="card p-5 space-y-3">
            <h2 class="card-title">④ ملاحظات واجهتني أو استفسارات</h2>
            <div>
                <label class="label">ملاحظات</label>
                <textarea name="notes" rows="3" class="input" x-model="notes" maxlength="5000"></textarea>
            </div>
            <div>
                <label class="label">استفسارات</label>
                <textarea name="inquiries" rows="2" class="input" x-model="inquiries" maxlength="5000"></textarea>
            </div>
            <div>
                <p class="label">مشكلات تُحوَّل للإدارة (تُنشأ عند اعتماد التقرير)</p>
                <template x-for="(iss, n) in issues" :key="iss.key">
                    <div class="mb-2 grid gap-2 rounded-xl border border-slate-200 p-3 sm:grid-cols-4">
                        <input type="hidden" :name="`issue_requests[${n}][key]`" :value="iss.key">
                        <select class="input" :name="`issue_requests[${n}][store_id]`" x-model="iss.store_id">
                            <template x-for="s in stores" :key="s.store_id"><option :value="s.store_id" x-text="storeName(s.store_id)" :selected="s.store_id == iss.store_id"></option></template>
                        </select>
                        <select class="input" :name="`issue_requests[${n}][type]`" x-model="iss.type">
                            @foreach($issueTypes as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                        </select>
                        <input class="input" :name="`issue_requests[${n}][title]`" x-model="iss.title" placeholder="العنوان" maxlength="200">
                        <div class="flex gap-1">
                            <input class="input" :name="`issue_requests[${n}][description]`" x-model="iss.description" placeholder="الوصف">
                            <button type="button" class="btn btn-ghost btn-sm" x-on:click="issues.splice(n, 1)"><x-icon name="x" class="size-4"/></button>
                        </div>
                    </div>
                </template>
                <button type="button" class="btn btn-light btn-sm" x-on:click="addIssue()" x-bind:disabled="!stores.length">+ تحويل ملاحظة إلى مشكلة على متجر</button>
            </div>
            @foreach($errors->get('issue_requests.*') as $msgs) @foreach($msgs as $m)<p class="field-error">{{ $m }}</p>@endforeach @endforeach
        </section>

        <div id="submit-bar" class="card p-5 flex flex-wrap items-end gap-3 scroll-mt-20">
            <input type="hidden" name="action" value="save" x-ref="action">
            <button type="submit" class="btn btn-primary" x-on:click="$refs.action.value = 'save'">حفظ مسودة</button>
            @can('submit', $report)
                <div class="ms-auto flex flex-wrap items-end gap-2">
                    <div>
                        <label class="label">نسبة التسليم النهائية % (عند إنهاء الشفت)</label>
                        <input name="end_delivery_rate" inputmode="decimal" value="{{ old('end_delivery_rate', $report->end_delivery_rate) }}" class="input !w-32 @error('end_delivery_rate') input-error @enderror">
                    </div>
                    <button type="submit" class="btn btn-accent" x-on:click="$refs.action.value = 'submit'">حفظ وإرسال للمراجعة</button>
                </div>
            @endcan
            @error('end_delivery_rate')<p class="field-error w-full">{{ $message }}</p>@enderror
            @error('stores')<p class="field-error w-full">{{ $message }}</p>@enderror
        </div>
    </form>

    @if(! $report->is_legacy && auth()->user()->can('imports.create'))
        @php $batches = \App\Models\ImportBatch::where('daily_report_id', $report->id)->whereIn('report_type', ['shift_start', 'shift_end'])->latest('id')->get(); @endphp
        <section class="card p-5 mt-5 space-y-3">
            <h2 class="card-title">ملفات منظومة الشحن</h2>
            <p class="hint">احفظ المسودة قبل رفع ملف — الرفع ينقلك لصفحة المعاينة.</p>
            @foreach(['shift_start' => 'رفع ملف البداية', 'shift_end' => 'رفع ملف النهاية'] as $kind => $label)
                <form method="POST" action="{{ route('imports.shift.upload') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="hidden" name="kind" value="{{ $kind }}">
                    <input type="hidden" name="report_id" value="{{ $report->id }}">
                    <input type="file" name="file" accept=".xlsx,.xls,.csv" required class="text-sm">
                    <button class="btn btn-light btn-sm"><x-icon name="upload" class="size-4"/> {{ $label }}</button>
                </form>
            @endforeach
            @error('file')<p class="field-error">{{ $message }}</p>@enderror
            @foreach($batches as $b)
                <p class="text-sm"><a class="text-sky-700 underline" href="{{ route('imports.show', $b) }}">{{ \App\Models\ImportBatch::TYPES[$b->report_type] }} — {{ $b->original_name }}</a> <x-badge :enum="$b->status"/></p>
            @endforeach
        </section>
    @endif

    @include('reports._screens', ['editable' => true])
</x-layouts.app>
