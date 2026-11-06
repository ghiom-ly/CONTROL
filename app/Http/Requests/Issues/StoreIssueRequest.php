<?php

namespace App\Http\Requests\Issues;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Issue::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::enum(IssueType::class)],
            'description' => ['required', 'string', 'max:5000'],
            'priority' => ['required', Rule::enum(IssuePriority::class)],
            'shipment_code' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function attributes(): array
    {
        return ['title' => 'العنوان', 'type' => 'النوع', 'description' => 'الوصف', 'priority' => 'الأولوية', 'shipment_code' => 'الكود'];
    }
}
