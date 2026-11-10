{{-- نموذج الموظف (إضافة/تعديل). $user = null للإضافة --}}
@php $isEdit = isset($user) && $user; $v = fn ($f, $d = null) => old($f, $isEdit ? $user->{$f} : $d); @endphp
<form method="POST" action="{{ $isEdit ? route('users.update', $user) : route('users.store') }}" class="space-y-4">
    @csrf
    @if($isEdit) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label class="label">الاسم الكامل <span class="text-red-500">*</span></label>
            <input name="name" value="{{ $v('name') }}" required maxlength="150" class="input @error('name') input-error @enderror">
            @error('name')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label">اسم المستخدم <span class="text-red-500">*</span></label>
            <input name="username" value="{{ $v('username') }}" required maxlength="60" class="input ltr text-right @error('username') input-error @enderror" placeholder="ahmed.salem">
            <p class="hint">أحرف إنجليزية صغيرة وأرقام و . - _</p>
            @error('username')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label">رقم الهاتف</label>
            <input name="phone" type="tel" value="{{ $v('phone') }}" maxlength="30" class="input text-right @error('phone') input-error @enderror" placeholder="0912345678">
            @error('phone')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label">البريد الإلكتروني</label>
            <input name="email" type="email" value="{{ $v('email') }}" maxlength="150" class="input ltr text-right @error('email') input-error @enderror">
            @error('email')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label">تاريخ التعيين</label>
            <input name="hired_at" type="date" value="{{ old('hired_at', $isEdit ? $user->hired_at?->toDateString() : null) }}" max="{{ now()->toDateString() }}" class="input @error('hired_at') input-error @enderror">
            @error('hired_at')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label">الدور الوظيفي <span class="text-red-500">*</span></label>
            <select name="role_id" required class="input @error('role_id') input-error @enderror">
                @foreach($roles as $role)
                    <option value="{{ $role->id }}" @selected((int) $v('role_id', $roles->firstWhere('name', 'employee')?->id) === $role->id)>{{ $role->display_name }}</option>
                @endforeach
            </select>
            @error('role_id')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label">نوع العمل (قالب التقرير)</label>
            <select name="work_type" class="input @error('work_type') input-error @enderror">
                <option value="followup" @selected($v('work_type', 'followup') === 'followup')>متابعة شحنات</option>
                <option value="confirmation" @selected($v('work_type', 'followup') === 'confirmation')>تأكيد طلبيات</option>
            </select>
            @error('work_type')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        @php $currentShift = $isEdit ? \App\Models\EmployeeShiftTime::currentFor($user->id) : null; @endphp
        <div>
            <label class="label">وقت بداية الشفت</label>
            <input name="shift_start" type="time" value="{{ old('shift_start', $currentShift) }}" class="input ltr @error('shift_start') input-error @enderror">
            <p class="hint">فارغ = الوقت العام في الإعدادات ({{ \App\Models\SystemSetting::get('attendance_late_after', '10:00') }}). التأخير يُحسب من هذا الوقت.</p>
            @error('shift_start')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label">يسري وقت الشفت من</label>
            <input name="shift_effective_from" type="date" value="{{ old('shift_effective_from', now()->toDateString()) }}" class="input">
            <p class="hint">الأيام قبل هذا التاريخ تبقى على الوقت السابق.</p>
            @error('shift_effective_from')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        @if(auth()->user()->isSuperAdmin())
            @php $currentSalary = $isEdit ? \App\Models\EmployeeSalary::currentFor($user->id) : null; @endphp
            <div>
                <label class="label">الراتب الأساسي (د.ل)</label>
                <input name="base_salary" inputmode="decimal" value="{{ old('base_salary', $currentSalary === null ? '' : \App\Models\PayrollItem::money($currentSalary)) }}" class="input ltr @error('base_salary') input-error @enderror">
                <p class="hint">يظهر للمدير العام فقط. فارغ = لا يدخل كشف الرواتب.</p>
                @error('base_salary')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label">يسري الراتب من</label>
                <input name="salary_effective_from" type="date" value="{{ old('salary_effective_from', now()->startOfMonth()->toDateString()) }}" class="input">
                <p class="hint">الكشف يأخذ الراتب الساري أول الشهر — الشهور السابقة لا تتأثر.</p>
                @error('salary_effective_from')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        @endif
        <div>
            <label class="label">الحالة <span class="text-red-500">*</span></label>
            <select name="status" required class="input">
                @foreach(\App\Enums\UserStatus::options() as $val => $label)
                    <option value="{{ $val }}" @selected(($isEdit ? old('status', $user->status->value) : old('status', 'active')) === $val)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label">كلمة المرور @unless($isEdit)<span class="text-red-500">*</span>@endunless</label>
            <input name="password" type="password" {{ $isEdit ? '' : 'required' }} autocomplete="new-password" class="input ltr text-right @error('password') input-error @enderror">
            <p class="hint">{{ $isEdit ? 'اتركها فارغة للإبقاء على كلمة المرور الحالية.' : '8 أحرف على الأقل، حروف وأرقام. سيُطلب من الموظف تغييرها.' }}</p>
            @error('password')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label">تأكيد كلمة المرور</label>
            <input name="password_confirmation" type="password" {{ $isEdit ? '' : 'required' }} autocomplete="new-password" class="input ltr text-right">
        </div>
    </div>
    <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
        <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', '{{ $isEdit ? 'edit-user' : 'create-user' }}')">إلغاء</button>
        <button type="submit" class="btn btn-primary">{{ $isEdit ? 'حفظ التعديلات' : 'إنشاء الحساب' }}</button>
    </div>
</form>
