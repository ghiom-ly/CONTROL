<?php

namespace App\Http\Requests\Reports;

use App\Enums\StoreStatus;
use App\Support\CancelReasons;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('report'));
    }

    protected function prepareForValidation(): void
    {
        // صفوف الإلغاء الفارغة تماماً (بلا كود) تُحذف قبل التحقق
        $stores = collect($this->input('stores', []))->map(function ($s) {
            if (is_array($s) && isset($s['cancelled']) && is_array($s['cancelled'])) {
                $s['cancelled'] = array_values(array_filter($s['cancelled'], fn ($c) => is_array($c) && trim((string) ($c['code'] ?? '')) !== ''));
            }

            return $s;
        })->values()->all();
        $times = collect((array) $this->input('attempt_times', []))
            ->map(fn ($t) => $t === null ? null : \App\Support\ArabicNormalizer::digits(trim((string) $t)))
            ->filter(fn ($t) => $t !== null && $t !== '')->values()->all();
        $this->merge(['stores' => $stores, 'attempt_times' => $times]);
    }

    /** نفس قاعدة التقرير العادي: تجاوز الحد الأقصى للمحاولات يتطلب سبباً */
    public function withValidator($v): void
    {
        $v->after(function ($v) {
            $max = \App\Services\Reports\ReportDraftService::maxAttempts();
            if (count($this->input('attempt_times', [])) > $max && ! filled($this->input('attempts_over_limit_reason'))) {
                $v->errors()->add('attempts_over_limit_reason', "الحد الأقصى {$max} محاولة في الشفت — اذكر سبب التجاوز.");
            }
        });
    }

    public function rules(): array
    {
        return [
            'action' => ['nullable', Rule::in(['save', 'submit'])],
            'stores' => ['array', 'max:200'],
            'stores.*.store_id' => ['required', 'integer', 'distinct',
                Rule::exists('stores', 'id')->where(fn ($q) => $q->where('status', '!=', StoreStatus::Archived->value)->whereNull('deleted_at')->whereIn('service_type', ['both', 'confirmation']))],
            'stores.*.confirmed' => ['nullable', 'string', 'max:20000'],
            'stores.*.no_answer' => ['nullable', 'string', 'max:20000'],
            'stores.*.cancelled' => ['array', 'max:500'],
            'stores.*.cancelled.*.code' => ['required', 'string', 'max:80'],
            'stores.*.cancelled.*.reason' => ['required', Rule::in([...CancelReasons::all(), CancelReasons::OTHER])],
            'stores.*.cancelled.*.other' => ['nullable', 'string', 'max:120', 'required_if:stores.*.cancelled.*.reason,'.CancelReasons::OTHER],
            'attempt_times' => ['array', 'max:20'],
            'attempt_times.*' => ['date_format:H:i'],
            'attempts_over_limit_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'stores.*.cancelled.*.reason.required' => 'اختر سبب الإلغاء.',
            'stores.*.cancelled.*.reason.in' => 'سبب الإلغاء غير موجود في القائمة.',
            'stores.*.cancelled.*.other.required_if' => 'اكتب سبب الإلغاء.',
            'stores.*.store_id.distinct' => 'المتجر مكرر في التقرير.',
        ];
    }
}
