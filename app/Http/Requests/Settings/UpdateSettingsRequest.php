<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.manage');
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.company_name' => ['required', 'string', 'max:150'],
            'settings.company_phone' => ['nullable', 'string', 'max:30'],
            'settings.company_address' => ['nullable', 'string', 'max:255'],
            'settings.report_submission_deadline' => ['required', 'date_format:H:i'],
            'settings.report_review_sla_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'settings.employee_can_edit_rejected' => ['nullable', 'boolean'],
            'settings.store_inactive_days' => ['required', 'integer', 'min:2', 'max:90'],
            'settings.store_decline_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'settings.issue_resolution_sla_hours' => ['required', 'integer', 'min:1', 'max:2160'],
            'settings.shipment_url_template' => ['nullable', 'string', 'max:300', 'regex:/^https?:\/\/[^\s]+$/', 'regex:/\{code\}/'],
            'settings.confirmation_cancel_reasons' => ['sometimes', 'required', 'string', 'max:3000'],
            'settings.import_max_rows' => ['required', 'integer', 'min:10', 'max:100000'],
            'settings.attendance_late_after' => ['sometimes', 'required', 'date_format:H:i'],
            'settings.attendance_grace_minutes' => ['sometimes', 'required', 'integer', 'min:0', 'max:240'],
            'settings.attendance_start_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'settings.attendance_track_late' => ['nullable', 'boolean'],
            'settings.attendance_track_absence' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['settings.shipment_url_template.regex' => 'رابط الشحنة يجب أن يبدأ بـ https:// ويحتوي {code} مكان الكود.'];
    }

    public function attributes(): array
    {
        return [
            'settings.company_name' => 'اسم الشركة',
            'settings.report_submission_deadline' => 'آخر موعد لتقديم التقرير',
            'settings.store_inactive_days' => 'عدد أيام التوقف',
        ];
    }
}
