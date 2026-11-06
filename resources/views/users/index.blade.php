<x-layouts.app title="الموظفون">
    <x-page-header title="الموظفون" subtitle="إدارة حسابات الموظفين وأدوارهم ومتابعة أدائهم">
        <x-slot:actions>
            @can('create', \App\Models\User::class)
                <button type="button" class="btn btn-accent" x-on:click="$dispatch('open-modal', 'create-user')"><x-icon name="plus" class="size-4"/> إضافة موظف</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid grid-cols-3 gap-3 sm:max-w-xl">
        <x-stat-card label="نشط" :value="(int) ($counts['active'] ?? 0)" icon="check-circle" tone="green" class="!p-4"/>
        <x-stat-card label="غير نشط" :value="(int) ($counts['inactive'] ?? 0)" icon="power" tone="gray" class="!p-4"/>
        <x-stat-card label="موقوف" :value="(int) ($counts['suspended'] ?? 0)" icon="user-x" tone="red" class="!p-4"/>
    </div>

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 p-4 border-b border-slate-100 md:flex-row md:items-end" data-no-loading>
            <div class="flex-1">
                <label class="label">بحث</label>
                <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="الاسم، اسم المستخدم أو الهاتف" class="input" maxlength="100">
            </div>
            <div class="md:w-44">
                <label class="label">الدور</label>
                <select name="role" class="input">
                    <option value="">الكل</option>
                    @foreach($allRoles as $r)<option value="{{ $r->id }}" @selected(($filters['role'] ?? null) == $r->id)>{{ $r->display_name }}</option>@endforeach
                </select>
            </div>
            <div class="md:w-40">
                <label class="label">الحالة</label>
                <select name="status" class="input">
                    <option value="">الكل</option>
                    @foreach(\App\Enums\UserStatus::options() as $val => $label)<option value="{{ $val }}" @selected(($filters['status'] ?? null) === $val)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <label class="flex items-center gap-2 pb-3 text-sm text-slate-600">
                <input type="checkbox" name="trashed" value="1" @checked($filters['trashed'] ?? false) class="size-4 rounded border-slate-300"> المحذوفون
            </label>
            <div class="flex gap-2">
                <button class="btn btn-primary">تصفية</button>
                <a href="{{ route('users.index') }}" class="btn btn-light">مسح</a>
            </div>
        </form>

        @if($users->isEmpty())
            <x-empty-state title="لا يوجد موظفون مطابقون" icon="users">جرّب تعديل معايير البحث أو أضف موظفاً جديداً.</x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>الموظف</th><th>اسم المستخدم</th><th>الهاتف</th><th>الدور</th><th>الحالة</th>
                        <th>التقارير</th><th>المتاجر المسندة</th><th>آخر دخول</th><th></th>
                    </tr></thead>
                    <tbody>
                    @foreach($users as $u)
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-navy-50 text-sm font-bold text-navy-700">{{ $u->initials() }}</span>
                                    <div>
                                        <p class="font-bold text-navy-900">{{ $u->name }}</p>
                                        <p class="text-xs text-slate-400">{{ $u->hired_at ? 'منذ '.$u->hired_at->format('Y/m/d') : 'تاريخ التعيين غير مسجل' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="ltr text-right text-slate-600">{{ $u->username }}</td>
                            <td class="ltr text-right text-slate-600">{{ $u->phone ?? '—' }}</td>
                            <td><span class="badge {{ $u->role->name === 'super_admin' ? 'badge-orange' : 'badge-navy' }}">{{ $u->role->display_name }}</span></td>
                            <td>
                                @if($u->trashed())<span class="badge badge-gray">محذوف</span>@else<x-badge :enum="$u->status"/>@endif
                            </td>
                            <td class="tabular-nums">{{ number_format($u->daily_reports_count) }}</td>
                            <td class="tabular-nums">{{ number_format($u->assigned_stores_count) }}</td>
                            <td class="text-xs text-slate-500 whitespace-nowrap">{{ $u->last_login_at?->diffForHumans() ?? 'لم يسجل دخول' }}</td>
                            <td class="text-left whitespace-nowrap">
                                @if($u->trashed())
                                    @can('update', $u)
                                        <x-confirm-form :action="route('users.restore', $u->id)" method="PATCH" message="استعادة حساب {{ $u->name }}؟">
                                            <x-icon name="restore" class="size-4"/> استعادة
                                        </x-confirm-form>
                                    @endcan
                                    @include('users._purge', ['u' => $u])
                                @else
                                    <a href="{{ route('users.show', $u) }}" class="btn btn-light btn-sm"><x-icon name="eye" class="size-4"/> الملف والأداء</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $users->links() }}
        @endif
    </div>

    @can('create', \App\Models\User::class)
        <x-modal name="create-user" title="إضافة موظف جديد" :show="$errors->any() && old('_modal') !== 'edit'">
            @include('users._form', ['user' => null])
        </x-modal>
    @endcan
</x-layouts.app>
