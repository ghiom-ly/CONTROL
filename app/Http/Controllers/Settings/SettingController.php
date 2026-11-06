<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\AlertRule;
use App\Models\SystemSetting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function index(): View
    {
        $this->authorize('settings.manage');

        return view('settings.index', [
            'groups' => SystemSetting::orderBy('id')->get()->groupBy('group'),
            'alertRules' => AlertRule::orderBy('id')->get(),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $input = $request->validated('settings');
        $input['employee_can_edit_rejected'] = $request->boolean('settings.employee_can_edit_rejected') ? '1' : '0';

        DB::transaction(function () use ($input, $request) {
            $old = [];
            $new = [];
            foreach (SystemSetting::whereIn('key', array_keys($input))->get() as $setting) {
                $value = $input[$setting->key];
                $value = $value === null ? null : (string) $value;
                if ($setting->value !== $value) {
                    $old[$setting->key] = $setting->value;
                    $new[$setting->key] = $value;
                    $setting->update(['value' => $value, 'updated_by' => $request->user()->id]);
                }
            }
            if ($new) {
                AuditLogger::log('settings_updated', 'تعديل إعدادات النظام', null, $old, $new);
            }
        });

        return back()->with('success', 'تم حفظ الإعدادات.');
    }

    public function uploadLogo(\Illuminate\Http\Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']], [], ['logo' => 'الشعار']);

        $setting = SystemSetting::where('key', 'company_logo')->firstOrFail();
        $old = $setting->value;
        $path = $request->file('logo')->store('branding', \App\Support\Branding::DISK);
        $setting->update(['value' => $path, 'updated_by' => $request->user()->id]);
        if ($old && $old !== $path) {
            \Illuminate\Support\Facades\Storage::disk(\App\Support\Branding::DISK)->delete($old);
        }
        AuditLogger::log('logo_updated', 'تحديث شعار الشركة');

        return back()->with('success', 'تم رفع الشعار.');
    }

    public function deleteLogo(\Illuminate\Http\Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');
        $setting = SystemSetting::where('key', 'company_logo')->firstOrFail();
        if ($setting->value) {
            \Illuminate\Support\Facades\Storage::disk(\App\Support\Branding::DISK)->delete($setting->value);
        }
        $setting->update(['value' => null, 'updated_by' => $request->user()->id]);
        AuditLogger::log('logo_deleted', 'حذف شعار الشركة');

        return back()->with('success', 'تم حذف الشعار.');
    }

    /** تعديل قواعد التنبيه: التفعيل والمستوى والمعاملات الموجودة فقط (لا مفاتيح جديدة) */
    public function updateAlertRules(\Illuminate\Http\Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');
        $data = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*.is_enabled' => ['nullable', 'boolean'],
            'rules.*.level' => ['required', \Illuminate\Validation\Rule::enum(\App\Enums\AlertLevel::class)],
            'rules.*.parameters' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($data, $request) {
            foreach (AlertRule::whereIn('id', array_keys($data['rules']))->get() as $rule) {
                $in = $data['rules'][$rule->id];
                $params = [];
                foreach ($rule->parameters ?? [] as $key => $current) {
                    $v = $in['parameters'][$key] ?? $current;
                    $params[$key] = $key === 'deadline'
                        ? (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? (string) $v : $current)
                        : max(1, min(720, (int) $v));
                }
                $old = $rule->only(['is_enabled', 'level', 'parameters']);
                $rule->update(['is_enabled' => (bool) ($in['is_enabled'] ?? false), 'level' => $in['level'],
                    'parameters' => $params ?: $rule->parameters, 'updated_by' => $request->user()->id]);
                AuditLogger::log('alert_rule_updated', 'تعديل قاعدة تنبيه: '.$rule->name, null, $old, $rule->only(['is_enabled', 'level', 'parameters']));
            }
        });

        return back()->with('success', 'تم حفظ قواعد التنبيه.');
    }
}
