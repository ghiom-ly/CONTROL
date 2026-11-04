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
