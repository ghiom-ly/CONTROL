<x-layouts.app title="تغيير كلمة المرور">
    <x-page-header title="تغيير كلمة المرور" subtitle="استخدم 8 أحرف على الأقل تتضمن حروفاً وأرقاماً"/>
    <div class="card max-w-xl p-6">
        @if(auth()->user()->must_change_password)
            <div class="mb-5 rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm font-semibold text-amber-800">
                كلمة المرور الحالية مؤقتة. يجب تعيين كلمة مرور جديدة خاصة بك قبل استخدام النظام.
            </div>
        @endif
        <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
            @csrf @method('PUT')
            @foreach(['current_password' => 'كلمة المرور الحالية', 'password' => 'كلمة المرور الجديدة', 'password_confirmation' => 'تأكيد كلمة المرور الجديدة'] as $f => $l)
                <div>
                    <label class="label" for="{{ $f }}">{{ $l }}</label>
                    <input id="{{ $f }}" type="password" name="{{ $f }}" required class="input ltr text-right @error($f) input-error @enderror"
                           autocomplete="{{ $f === 'current_password' ? 'current-password' : 'new-password' }}">
                    @error($f)<p class="field-error">{{ $message }}</p>@enderror
                </div>
            @endforeach
            <button class="btn btn-primary">حفظ كلمة المرور</button>
        </form>
    </div>
</x-layouts.app>
