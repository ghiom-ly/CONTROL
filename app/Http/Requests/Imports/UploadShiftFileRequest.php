<?php

namespace App\Http\Requests\Imports;

use App\Models\DailyReport;
use Illuminate\Foundation\Http\FormRequest;

class UploadShiftFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()->hasPermission('imports.create')) {
            return false;
        }
        $report = $this->filled('report_id') ? DailyReport::find($this->input('report_id')) : null;

        return $report === null || $this->user()->can('update', $report);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:'.config('nours.uploads.max_size_kb'),
                // ملف منظومة الشحن لتقارير المتابعة فقط — لا لموظفات التأكيد ولا لتقرير تأكيد
                function ($attr, $v, $fail) {
                    $report = $this->filled('report_id') ? \App\Models\DailyReport::find($this->input('report_id')) : null;
                    if ($this->user()->isConfirmation() || $report?->isConfirmation()) {
                        $fail('ملف منظومة الشحن خاص بتقرير متابعة الشحنات — لا يُرفع لتقرير التأكيد.');
                    }
                }],
            'kind' => ['required', 'in:shift_start,shift_end'],
            'report_id' => ['nullable', 'required_if:kind,shift_end', 'integer', 'exists:daily_reports,id'],
        ];
    }

    public function attributes(): array
    {
        return ['file' => 'الملف', 'report_id' => 'التقرير'];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'يُقبل ملف Excel أو CSV فقط.',
            'report_id.required_if' => 'ملف نهاية الشفت يُرفع من صفحة التقرير.',
        ];
    }
}
