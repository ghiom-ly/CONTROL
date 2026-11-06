<?php

namespace App\Http\Requests\Issues;

use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Services\IssueService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('issue'));
    }

    public function rules(): array
    {
        $closing = in_array($this->input('status'), [IssueStatus::Resolved->value, IssueStatus::Closed->value], true);
        $hasAction = filled($this->route('issue')->action_taken);

        return [
            'comment' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::enum(IssueStatus::class)],
            'priority' => ['nullable', Rule::enum(IssuePriority::class)],
            'assigned_to' => ['nullable', 'integer', Rule::in(IssueService::managers()->pluck('id')->all())],
            'action_taken' => [$closing && ! $hasAction ? 'required' : 'nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'action_taken.required' => 'اكتب الإجراء المتخذ قبل حل المشكلة أو إغلاقها.',
            'assigned_to.in' => 'يمكن الإسناد فقط لمن يدير المشكلات.',
        ];
    }
}
