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
        // الحالة الناتجة محلولة/مغلقة ⇒ «الإجراء المتخذ» مطلوب دائماً (لا يُمسح بعد الحل)
        $issue = $this->route('issue');
        $resulting = $this->input('status', $issue->status->value);
        $closing = in_array($resulting, [IssueStatus::Resolved->value, IssueStatus::Closed->value], true);
        $sendsAction = $this->has('action_taken');

        return [
            'comment' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'required', Rule::enum(IssueStatus::class)],
            'priority' => ['sometimes', 'required', Rule::enum(IssuePriority::class)],
            'assigned_to' => ['nullable', 'integer', Rule::in(IssueService::managers()->pluck('id')->all())],
            'action_taken' => [$closing && ($sendsAction || ! filled($issue->action_taken)) ? 'required' : 'nullable', 'string', 'max:5000'],
        ];
    }

    /** تحديث بلا أي تغيير وبلا تعليق لا يُسجَّل */
    public function withValidator($v): void
    {
        $v->after(function ($v) {
            $i = $this->route('issue');
            $changed = ($this->filled('status') && $this->input('status') !== $i->status->value)
                || ($this->filled('priority') && $this->input('priority') !== $i->priority->value)
                || ($this->has('assigned_to') && (int) $this->input('assigned_to') !== (int) $i->assigned_to)
                || ($this->filled('action_taken') && $this->input('action_taken') !== $i->action_taken)
                || $this->filled('comment');
            if (! $changed) {
                $v->errors()->add('comment', 'لا يوجد تغيير — اكتب تعليقاً أو غيّر الحالة أو الأولوية أو المسؤول.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'action_taken.required' => 'اكتب الإجراء المتخذ قبل حل المشكلة أو إغلاقها.',
            'assigned_to.in' => 'يمكن الإسناد فقط لمن يدير المشكلات.',
        ];
    }
}
