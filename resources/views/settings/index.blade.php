<x-layouts.app title="الإعدادات">
    <x-page-header title="الإعدادات" subtitle="إعدادات النظام وقواعد العمل"/>
    @include('settings._tabs')
    @php $groupLabels = ['general' => 'بيانات الشركة', 'reports' => 'التقارير', 'stores' => 'المتاجر', 'issues' => 'المشكلات', 'imports' => 'الاستيراد', 'attendance' => 'الحضور والانصراف']; @endphp

    @php $brandLogo = \App\Support\Branding::header()['logo_path']; @endphp
    <div class="card mb-6 flex flex-wrap items-center gap-4 p-5">
        <div>
            <h2 class="card-title">شعار الشركة</h2>
            <p class="hint">يظهر في رأس كل التقارير المستخرجة (PNG أو JPG، حتى 2MB)</p>
        </div>
        @if($brandLogo)
            <img src="data:{{ mime_content_type($brandLogo) }};base64,{{ base64_encode(file_get_contents($brandLogo)) }}" alt="الشعار" class="h-14 rounded border border-slate-200 bg-white p-1">
            <form method="POST" action="{{ route('settings.logo.destroy') }}">@csrf @method('DELETE')<button class="btn btn-light btn-sm text-red-600">حذف الشعار</button></form>
        @endif
        <form method="POST" action="{{ route('settings.logo.store') }}" enctype="multipart/form-data" class="flex items-center gap-2">
            @csrf
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required class="text-sm">
            <button class="btn btn-primary btn-sm">رفع الشعار</button>
            @error('logo')<p class="field-error">{{ $message }}</p>@enderror
        </form>
    </div>

    <form method="POST" action="{{ route('settings.update') }}" class="space-y-6">
        @csrf @method('PUT')
        @foreach($groups as $group => $settings)
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ $groupLabels[$group] ?? $group }}</h2></div>
                <div class="grid gap-5 p-5 md:grid-cols-2">
                    @foreach($settings->where('key', '!=', 'company_logo') as $s)
                        @php $name = "settings[{$s->key}]"; $val = old("settings.{$s->key}", $s->value); @endphp
                        <div>
                            @if($s->type === 'boolean')
                                <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3">
                                    <input type="hidden" name="{{ $name }}" value="0">
                                    <input type="checkbox" name="{{ $name }}" value="1" @checked($val == '1') class="mt-1 size-4 rounded border-slate-300">
                                    <span><span class="block text-sm font-semibold text-slate-700">{{ $s->label }}</span>
                                        @if($s->description)<span class="hint block">{{ $s->description }}</span>@endif</span>
                                </label>
                            @elseif($s->type === 'text')
                                <label class="label">{{ $s->label }}</label>
                                <textarea name="{{ $name }}" rows="5" class="input @error("settings.{$s->key}") input-error @enderror">{{ $val }}</textarea>
                                @if($s->description)<p class="hint">{{ $s->description }}</p>@endif
                                @error("settings.{$s->key}")<p class="field-error">{{ $message }}</p>@enderror
                            @else
                                <label class="label">{{ $s->label }}</label>
                                <input name="{{ $name }}" value="{{ $val }}"
                                       type="{{ $s->type === 'integer' ? 'number' : ($s->type === 'time' ? 'time' : ($s->type === 'date' ? 'date' : 'text')) }}"
                                       class="input @error("settings.{$s->key}") input-error @enderror">
                                @if($s->description)<p class="hint">{{ $s->description }}</p>@endif
                                @error("settings.{$s->key}")<p class="field-error">{{ $message }}</p>@enderror
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
        <div class="flex justify-end"><button class="btn btn-primary">حفظ الإعدادات</button></div>
    </form>

    <div class="card mt-8">
        <div class="card-header">
            <h2 class="card-title">قواعد التنبيهات</h2>
            <span class="text-xs text-slate-500">تُفحص كل ساعة — النتائج في صفحة «التنبيهات»</span>
        </div>
        @php
            $paramLabels = ['percent' => 'نسبة الانخفاض %', 'compare_days' => 'أيام المقارنة', 'days' => 'عدد الأيام', 'count' => 'عدد المشكلات',
                'hours' => 'عدد الساعات', 'deadline' => 'الموعد (س:د)'];
        @endphp
        <form method="POST" action="{{ route('settings.alert-rules.update') }}">
            @csrf @method('PUT')
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>مفعّلة</th><th>القاعدة</th><th>المستوى</th><th>المعاملات</th></tr></thead>
                    <tbody>
                    @foreach($alertRules as $rule)
                        <tr>
                            <td><input type="hidden" name="rules[{{ $rule->id }}][is_enabled]" value="0">
                                <input type="checkbox" name="rules[{{ $rule->id }}][is_enabled]" value="1" @checked($rule->is_enabled) class="size-4"></td>
                            <td class="font-semibold">{{ $rule->name }}</td>
                            <td><select name="rules[{{ $rule->id }}][level]" class="input !w-32">
                                @foreach(\App\Enums\AlertLevel::cases() as $l)<option value="{{ $l->value }}" @selected($rule->level === $l)>{{ $l->label() }}</option>@endforeach
                            </select></td>
                            <td class="flex flex-wrap gap-2">
                                @forelse($rule->parameters ?? [] as $key => $val)
                                    <label class="text-xs">{{ $paramLabels[$key] ?? $key }}
                                        <input name="rules[{{ $rule->id }}][parameters][{{ $key }}]" value="{{ $val }}" class="input !w-24 ltr" {{ $key === 'deadline' ? 'pattern=[0-2][0-9]:[0-5][0-9]' : 'type=number min=1 max=720' }}>
                                    </label>
                                @empty — @endforelse
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex justify-end p-4"><button class="btn btn-primary">حفظ قواعد التنبيه</button></div>
        </form>
    </div>
</x-layouts.app>
