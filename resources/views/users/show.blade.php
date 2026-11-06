<x-layouts.app :title="$user->name">
    <x-page-header :title="$user->name" :subtitle="$user->role->display_name.' — '.$user->username.($user->hasRole(\App\Models\Role::EMPLOYEE) ? ' — '.($user->isConfirmation() ? 'تأكيد طلبيات' : 'متابعة شحنات') : '')">
        <x-slot:actions>
            <x-period-filter :period="$period"/>
        </x-slot:actions>
    </x-page-header>

    @if(session('temp_password'))
        <div class="mb-5 rounded-xl border-2 border-dashed border-brand-300 bg-brand-50 p-4">
            <p class="text-sm font-bold text-brand-800">كلمة المرور المؤقتة للموظف (تظهر مرة واحدة فقط):</p>
            <p class="mt-2 select-all rounded-lg bg-white px-4 py-2 text-center text-xl font-extrabold tracking-widest text-navy-900 ltr">{{ session('temp_password') }}</p>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        {{-- بطاقة البيانات --}}
        <div class="card p-5 xl:col-span-1 h-fit">
            <div class="flex items-center gap-4">
                <span class="grid size-16 place-items-center rounded-2xl bg-navy-800 text-2xl font-extrabold text-white">{{ $user->initials() }}</span>
                <div>
                    <p class="text-lg font-extrabold text-navy-900">{{ $user->name }}</p>
                    <div class="mt-1 flex flex-wrap gap-1.5">
                        <x-badge :enum="$user->status"/>
                        <span class="badge badge-navy">{{ $user->role->display_name }}</span>
                    </div>
                </div>
            </div>
            <dl class="mt-6 divide-y divide-slate-100 text-sm">
                @foreach([
                    'اسم المستخدم' => $user->username,
                    'الهاتف' => $user->phone ?? '—',
                    'البريد الإلكتروني' => $user->email ?? '—',
                    'تاريخ التعيين' => $user->hired_at?->format('Y/m/d') ?? '—',
                    'آخر دخول' => $user->last_login_at?->format('Y/m/d H:i') ?? 'لم يسجل دخول',
                    'أُنشئ بواسطة' => $user->creator?->name ?? 'النظام',
                ] as $k => $val)
                    <div class="flex justify-between gap-3 py-2.5"><dt class="text-slate-500">{{ $k }}</dt><dd class="font-semibold text-slate-800 text-left">{{ $val }}</dd></div>
                @endforeach
            </dl>

            @can('update', $user)
                <div class="mt-5 flex flex-wrap gap-2 border-t border-slate-100 pt-5">
                    <button type="button" class="btn btn-primary btn-sm" x-on:click="$dispatch('open-modal', 'edit-user')"><x-icon name="pencil" class="size-4"/> تعديل</button>
                    <x-confirm-form :action="route('users.reset-password', $user)" message="سيتم إنشاء كلمة مرور مؤقتة جديدة وإنهاء جميع جلسات {{ $user->name }}. متابعة؟">
                        <x-icon name="key" class="size-4"/> إعادة تعيين كلمة المرور
                    </x-confirm-form>
                    @unless($user->is(auth()->user()))
                        <x-confirm-form :action="route('users.toggle-status', $user)" method="PATCH"
                                        :message="$user->isActive() ? 'إيقاف الحساب سيمنع الدخول وينهي الجلسات الحالية. متابعة؟' : 'تفعيل الحساب؟'">
                            <x-icon name="power" class="size-4"/> {{ $user->isActive() ? 'إيقاف الحساب' : 'تفعيل الحساب' }}
                        </x-confirm-form>
                    @endunless
                    @can('delete', $user)
                        <x-confirm-form :action="route('users.destroy', $user)" method="DELETE" class="btn btn-sm bg-red-50 text-red-700 hover:bg-red-100"
                                        message="حذف الحساب؟ ستبقى تقاريره وسجلاته محفوظة ويمكن استعادته لاحقاً.">
                            <x-icon name="trash" class="size-4"/> حذف
                        </x-confirm-form>
                    @endcan
                    @include('users._purge', ['u' => $user])
                </div>
            @endcan
        </div>

        {{-- مؤشرات الأداء --}}
        <div class="xl:col-span-2 space-y-6">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">مؤشرات الأداء — {{ $period->label() }}</h2>
                    <span class="badge badge-navy">من التقارير المعتمدة</span>
                </div>
                @php
                    $kpis = [
                        ['عدد التقارير', 'reports_total', 'report', 'navy'],
                        ['التقارير المعتمدة', 'reports_approved', 'check-circle', 'green'],
                        ['المتاجر المتابعة', 'stores_followed', 'store', 'blue'],
                        ['الشحنات التي تمت مراجعتها', 'shipments_reviewed', 'box', 'navy'],
                        ['الأكواد التي تمت متابعتها', 'codes_followed', 'truck', 'orange'],
                        ['سُلّمت بعد المتابعة', 'delivered_after_followup', 'check', 'green'],
                        ['محاولات الاتصال', 'contact_attempts', 'phone', 'blue'],
                        ['المشكلات المسجلة', 'issues_reported', 'alert', 'yellow'],
                        ['التقارير المتأخرة', 'late_reports', 'clock', 'red'],
                    ];
                @endphp
                <div class="grid grid-cols-2 gap-3 p-4 md:grid-cols-3">
                    @foreach($kpis as [$label, $key, $icon, $tone])
                        @php
                            $cur = $metrics[$key]; $prev = $previous[$key];
                            $diff = ($cur !== null && $prev !== null) ? $cur - $prev : null;
                        @endphp
                        <div class="rounded-xl border border-slate-100 p-4">
                            <div class="flex items-center gap-2 text-slate-500">
                                <x-icon :name="$icon" class="size-4"/><span class="text-xs font-semibold">{{ $label }}</span>
                            </div>
                            <p class="mt-2 text-2xl font-extrabold text-navy-900 tabular-nums">{{ $cur === null ? 'غير متوفر' : number_format($cur) }}</p>
                            @if($diff !== null)
                                <p class="mt-1 text-xs font-semibold {{ $diff > 0 ? 'text-emerald-600' : ($diff < 0 ? 'text-red-600' : 'text-slate-400') }}">
                                    {{ $diff > 0 ? '▲ +' : ($diff < 0 ? '▼ ' : '') }}{{ number_format($diff) }} عن الفترة السابقة ({{ number_format($prev) }})
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card p-5">
                <div class="flex items-center justify-between">
                    <h2 class="card-title">نسبة اكتمال التقارير</h2>
                    <span class="text-2xl font-extrabold text-navy-900 tabular-nums">{{ $metrics['completion_rate'] === null ? '—' : $metrics['completion_rate'].'%' }}</span>
                </div>
                <div class="mt-3 h-3 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-gradient-to-l from-brand-500 to-navy-700" style="width: {{ min(100, $metrics['completion_rate'] ?? 0) }}%"></div>
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    {{ $metrics['days_with_report'] }} يوم بتقرير معتمد من أصل {{ $metrics['working_days'] }} يوم عمل في الفترة (باستثناء الجمعة).
                    توزيع الحالات: مسودة {{ $metrics['reports_draft'] }} · قيد المراجعة {{ $metrics['reports_pending'] }} · مرفوض {{ $metrics['reports_rejected'] }}.
                </p>
            </div>
        </div>
    </div>

    @can('update', $user)
        <x-modal name="edit-user" title="تعديل بيانات {{ $user->name }}" :show="$errors->any()">
            @include('users._form', ['user' => $user])
        </x-modal>
    @endcan
</x-layouts.app>
