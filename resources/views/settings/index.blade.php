<x-layouts.app title="الإعدادات">
    <x-page-header title="الإعدادات" subtitle="إعدادات النظام وقواعد العمل"/>
    @include('settings._tabs')
    @php $groupLabels = ['general' => 'بيانات الشركة', 'reports' => 'التقارير', 'stores' => 'المتاجر', 'issues' => 'المشكلات', 'imports' => 'الاستيراد']; @endphp

    <form method="POST" action="{{ route('settings.update') }}" class="space-y-6">
        @csrf @method('PUT')
        @foreach($groups as $group => $settings)
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ $groupLabels[$group] ?? $group }}</h2></div>
                <div class="grid gap-5 p-5 md:grid-cols-2">
                    @foreach($settings as $s)
                        @php $name = "settings[{$s->key}]"; $val = old("settings.{$s->key}", $s->value); @endphp
                        <div>
                            @if($s->type === 'boolean')
                                <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3">
                                    <input type="hidden" name="{{ $name }}" value="0">
                                    <input type="checkbox" name="{{ $name }}" value="1" @checked($val == '1') class="mt-1 size-4 rounded border-slate-300">
                                    <span><span class="block text-sm font-semibold text-slate-700">{{ $s->label }}</span>
                                        @if($s->description)<span class="hint block">{{ $s->description }}</span>@endif</span>
                                </label>
                            @else
                                <label class="label">{{ $s->label }}</label>
                                <input name="{{ $name }}" value="{{ $val }}"
                                       type="{{ $s->type === 'integer' ? 'number' : ($s->type === 'time' ? 'time' : 'text') }}"
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
            <span class="badge badge-gray">قابلة للتعديل وتُفعَّل في المرحلة 5</span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>القاعدة</th><th>المستوى</th><th>المعاملات</th><th>الحالة</th></tr></thead>
                <tbody>
                @foreach($alertRules as $rule)
                    <tr>
                        <td class="font-semibold">{{ $rule->name }}</td>
                        <td><x-badge :enum="$rule->level"/></td>
                        <td class="text-xs text-slate-500 ltr text-right">{{ $rule->parameters ? json_encode($rule->parameters, JSON_UNESCAPED_UNICODE) : '—' }}</td>
                        <td>{!! $rule->is_enabled ? '<span class="badge badge-green">مفعّلة</span>' : '<span class="badge badge-gray">معطّلة</span>' !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
