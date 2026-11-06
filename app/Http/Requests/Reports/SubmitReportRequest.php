<?php

namespace App\Http\Requests\Reports;

use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class SubmitReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // الإرسال لصاحب التقرير فقط — حتى المدير العام لا يرسل مسودة غيره (Gate::before لا يكفي هنا)
        return $this->route('report')->user_id === $this->user()->id && $this->user()->can('submit', $this->route('report'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('end_delivery_rate')) {
            $this->merge(['end_delivery_rate' => str_replace(['٫', ','], '.', ArabicNormalizer::digits((string) $this->input('end_delivery_rate')))]);
        }
    }

    public function rules(): array
    {
        $r = $this->route('report');

        return ['end_delivery_rate' => [\Illuminate\Validation\Rule::requiredIf(fn () => ! $r->is_legacy && ! $r->isConfirmation()), 'nullable', 'numeric', 'min:0', 'max:100']];
    }

    public function attributes(): array
    {
        return ['end_delivery_rate' => 'نسبة التسليم النهائية'];
    }
}
