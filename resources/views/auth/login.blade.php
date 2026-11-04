<x-layouts.guest title="تسجيل الدخول">
<div class="grid min-h-screen lg:grid-cols-2">
    <div class="relative hidden overflow-hidden lg:flex flex-col justify-between p-12 text-white
                bg-[radial-gradient(90%_70%_at_0%_100%,rgba(244,114,22,.28),transparent_60%),radial-gradient(70%_60%_at_100%_0%,rgba(69,101,163,.45),transparent_60%)]">
        <div class="flex items-center gap-3">
            <div class="grid size-12 place-items-center rounded-2xl bg-brand-500 text-2xl font-extrabold shadow-lg shadow-brand-500/40">ن</div>
            <div>
                <p class="font-extrabold tracking-wide">NOURS STORE MONITORING</p>
                <p class="text-sm text-navy-200">شركة النورس</p>
            </div>
        </div>
        <div class="max-w-md">
            <h1 class="text-4xl font-extrabold leading-tight">نظام إدارة تقارير<br>ومتابعة المتاجر</h1>
            <p class="mt-4 text-navy-200 leading-8">متابعة التقارير اليومية للموظفين، حركة الشحنات، نتائج الاتصالات والمشكلات — في مكان واحد، وبأرقام حقيقية معتمدة.</p>
            <div class="mt-8 grid grid-cols-3 gap-3 text-center">
                @foreach(['التقارير اليومية', 'متابعة الشحنات', 'التحليلات'] as $f)
                    <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-4 text-sm font-semibold">{{ $f }}</div>
                @endforeach
            </div>
        </div>
        <p class="text-xs text-navy-300">© {{ now()->year }} شركة النورس — جميع الحقوق محفوظة</p>
    </div>

    <div class="flex items-center justify-center bg-surface p-6">
        <div class="w-full max-w-md">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <div class="grid size-11 place-items-center rounded-xl bg-navy-900 text-xl font-extrabold text-brand-400">ن</div>
                <p class="font-extrabold text-navy-900">NOURS STORE MONITORING</p>
            </div>
            <div class="card p-8">
                <h2 class="text-2xl font-extrabold text-navy-900">تسجيل الدخول</h2>
                <p class="mt-1 text-sm text-slate-500">أدخل بيانات حسابك للمتابعة</p>

                <form method="POST" action="{{ route('login.store') }}" class="mt-7 space-y-5">
                    @csrf
                    <div>
                        <label for="username" class="label">اسم المستخدم</label>
                        <input id="username" name="username" type="text" value="{{ old('username') }}" required autofocus autocomplete="username"
                               class="input ltr text-right @error('username') input-error @enderror" maxlength="60">
                        @error('username')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div x-data="{ show: false }">
                        <label for="password" class="label">كلمة المرور</label>
                        <div class="relative">
                            <input id="password" name="password" :type="show ? 'text' : 'password'" required autocomplete="current-password"
                                   class="input ltr text-right pe-11 @error('password') input-error @enderror">
                            <button type="button" x-on:click="show = !show" class="absolute end-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-navy-700" aria-label="إظهار كلمة المرور">
                                <x-icon name="eye" class="size-5"/>
                            </button>
                        </div>
                        @error('password')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="remember" value="1" class="size-4 rounded border-slate-300 text-navy-700 focus:ring-navy-500">
                        تذكرني على هذا الجهاز
                    </label>
                    <button type="submit" class="btn btn-primary w-full !py-3 text-base">دخول</button>
                </form>
            </div>
            <p class="mt-6 text-center text-xs text-slate-400">في حال نسيان كلمة المرور، تواصل مع المدير العام لإعادة تعيينها.</p>
        </div>
    </div>
</div>
</x-layouts.guest>
