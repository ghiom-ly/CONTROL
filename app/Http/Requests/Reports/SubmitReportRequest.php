<?php

namespace App\Http\Requests\Reports;

use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class SubmitReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('submit', $this->route('report'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('end_delivery_rate')) {
            $this->merge(['end_delivery_rate' => str_replace(['٫', ','], '.', ArabicNormalizer::digits((string) $this->input('end_delivery_rate')))]);
        }
    }

    public function rules(): array
    {
        return ['end_delivery_rate' => ['required', 'numeric', 'min:0', 'max:100']];
    }

    public function attributes(): array
    {
        return ['end_delivery_rate' => 'نسبة التسليم النهائية'];
    }
}
