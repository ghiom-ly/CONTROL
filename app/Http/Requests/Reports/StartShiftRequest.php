<?php

namespace App\Http\Requests\Reports;

use App\Models\DailyReport;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class StartShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', DailyReport::class);
    }

    protected function prepareForValidation(): void
    {
        foreach (['start_total_orders', 'start_delivery_rate'] as $f) {
            if ($this->filled($f)) {
                $this->merge([$f => str_replace(['٫', ','], '.', ArabicNormalizer::digits((string) $this->input($f)))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'start_total_orders' => ['required', 'integer', 'min:0', 'max:100000'],
            'start_delivery_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return ['start_total_orders' => 'إجمالي الطلبيات المكلّف بها', 'start_delivery_rate' => 'نسبة التسليم الحالية'];
    }
}
