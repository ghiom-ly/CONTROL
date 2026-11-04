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
}
