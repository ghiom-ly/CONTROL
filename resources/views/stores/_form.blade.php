{{-- نموذج المتجر (إضافة/تعديل). $store = null للإضافة --}}
@php $isEdit = isset($store) && $store; $v = fn ($f, $d = null) => old($f, $isEdit ? $store->{$f} : $d); @endphp
<form method="POST" action="{{ $isEdit ? route('stores.update', $store) : route('stores.store') }}" class="space-y-4">
    @csrf
    @if($isEdit) @method('PUT') <input type="hidden" name="_modal" value="edit"> @endif
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label class="label">اسم المتجر <span class="text-red-500">*</span></label>
            <input name="name" value="{{ $v('name') }}" required maxlength="200" class="input @error('name') input-error @enderror">
            @error('name')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label">اسم صاحب المتجر</label>
            <input name="owner_name" value="{{ $v('owner_name') }}" maxlength="150" class="input @error('owner_name') input-error @enderror">
            @error('owner_name')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label">رقم الهاتف</label>
            <input name="phone" type="tel" value="{{ $v('phone') }}" maxlength="30" class="input text-right @error('phone') input-error @enderror" placeholder="0912345678">
            @error('phone')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label">الموظف المسؤول</label>
            <select name="assigned_user_id" class="input @error('assigned_user_id') input-error @enderror">
                <option value="">— بدون —</option>
                @foreach($employees as $emp)
                    <option value="{{ $emp->id }}" @selected((int) $v('assigned_user_id') === $emp->id)>{{ $emp->name }}</option>
                @endforeach
            </select>
            @error('assigned_user_id')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label">الحالة <span class="text-red-500">*</span></label>
            <select name="status" required class="input @error('status') input-error @enderror">
                @foreach(\App\Enums\StoreStatus::options() as $val => $label)
                    <option value="{{ $val }}" @selected(($isEdit ? $store->status->value : old('status', 'active')) === $val)>{{ $label }}</option>
                @endforeach
            </select>
            @error('status')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label">تاريخ بدء التعامل</label>
            <input name="started_at" type="date" value="{{ old('started_at', $isEdit ? $store->started_at?->toDateString() : null) }}" max="{{ now()->toDateString() }}" class="input @error('started_at') input-error @enderror">
            @error('started_at')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label">متوسط الشحنات المتوقع يومياً</label>
            <input name="expected_avg_shipments" type="number" min="0" value="{{ $v('expected_avg_shipments') }}" class="input @error('expected_avg_shipments') input-error @enderror">
            @error('expected_avg_shipments')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div class="sm:col-span-2">
            <label class="label">البريد الإلكتروني</label>
            <input name="email" type="email" value="{{ $v('email') }}" maxlength="150" class="input ltr text-right @error('email') input-error @enderror">
            @error('email')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div class="sm:col-span-2">
            <label class="label">ملاحظات</label>
            <textarea name="notes" rows="3" maxlength="2000" class="input @error('notes') input-error @enderror">{{ $v('notes') }}</textarea>
            @error('notes')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    </div>
    <div class="flex justify-end gap-2 pt-2">
        <button type="submit" class="btn btn-primary">{{ $isEdit ? 'حفظ التعديلات' : 'إضافة المتجر' }}</button>
    </div>
</form>
