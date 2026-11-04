<?php

namespace App\Http\Requests\Imports;

use Illuminate\Foundation\Http\FormRequest;

class SaveMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('batch'));
    }

    public function rules(): array
    {
        return [
            'layout' => ['required', 'in:orders,stores'],
            'columns' => ['required', 'array'],
            'columns.store' => ['nullable', 'string', 'max:200'],
            'columns.status' => ['nullable', 'string', 'max:200'],
            'columns.code' => ['nullable', 'string', 'max:200'],
            'columns.total' => ['nullable', 'string', 'max:200'],
            'columns.delivered' => ['nullable', 'string', 'max:200'],
            'columns.rate' => ['nullable', 'string', 'max:200'],
            'delivered_values' => ['array'],
            'delivered_values.*' => ['string', 'max:200'],
            'store_choices' => ['array'],
            'store_choices.*' => ['nullable', function ($attr, $v, $fail) {
                if ($v !== null && $v !== 'ignore' && ! \App\Models\Store::whereKey($v)->exists()) {
                    $fail('المتجر المختار غير موجود.');
                }
            }],
        ];
    }
}
