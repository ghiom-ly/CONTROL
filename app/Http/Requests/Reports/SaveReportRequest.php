<?php

namespace App\Http\Requests\Reports;

use App\Enums\FollowupResult;
use App\Enums\IssueType;
use App\Enums\StoreStatus;
use App\Services\Reports\ReportDraftService;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('report'));
    }

    protected function prepareForValidation(): void
    {
        $stores = collect($this->input('stores', []))->map(function ($row) {
            if (isset($row['shipments_count']) && $row['shipments_count'] !== '') {
                $row['shipments_count'] = ArabicNormalizer::digits((string) $row['shipments_count']);
            }
            foreach (['start_orders', 'start_rate'] as $k) {
                if (isset($row[$k]) && $row[$k] !== '') {
                    $row[$k] = str_replace(['٫', ',', '%', '٪', ' '], ['.', '.', '', '', ''], ArabicNormalizer::digits((string) $row[$k]));
                }
            }
            // صفوف «+ إضافة كود» التي تُركت فارغة لا تمنع الحفظ
            if (isset($row['codes']) && is_array($row['codes'])) {
                $row['codes'] = array_values(array_filter($row['codes'], fn ($c) => is_array($c) && filled($c['code'] ?? null)));
            }

            return $row;
        })->values()->all();

        $times = collect($this->input('attempt_times', []))
            ->map(fn ($t) => $t === null ? null : ArabicNormalizer::digits(trim((string) $t)))
            ->filter(fn ($t) => $t !== null && $t !== '')->values()->all();

        $this->merge(['stores' => $stores, 'attempt_times' => $times]);

        if ($this->filled('end_delivery_rate')) {
            $this->merge(['end_delivery_rate' => str_replace(['٫', ','], '.', ArabicNormalizer::digits((string) $this->input('end_delivery_rate')))]);
        }
    }

    public function rules(): array
    {
        return [
            'action' => ['nullable', 'in:save,submit'],
            'approve_after' => ['nullable', 'boolean'],
            'screens' => ['array:courier_screens,customer_calls'],
            'screens.*' => ['array', 'max:20'],
            'screens.*.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('nours.uploads.max_size_kb')],
            'end_delivery_rate' => [Rule::requiredIf(fn () => $this->input('action') === 'submit' && ! $this->route('report')->is_legacy), 'nullable', 'numeric', 'min:0', 'max:100'],
            'stores' => ['array', 'max:200'],
            'stores.*.store_id' => ['required', 'integer', 'distinct',
                Rule::exists('stores', 'id')->where(fn ($q) => $q->where('status', '!=', StoreStatus::Archived->value)->whereNull('deleted_at'))],
            'stores.*.shipments_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'stores.*.start_orders' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'stores.*.start_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stores.*.notes' => ['nullable', 'string', 'max:2000'],
            'stores.*.codes' => ['array', 'max:500'],
            'stores.*.codes.*.code' => ['required', 'string', 'max:80'],
            'stores.*.codes.*.result' => ['required', Rule::enum(FollowupResult::class)],
            'unanswered.customer' => ['nullable', 'string', 'max:20000'],
            'unanswered.courier' => ['nullable', 'string', 'max:20000'],
            'unanswered.customer_store_id' => ['nullable', 'integer'],
            'unanswered.courier_store_id' => ['nullable', 'integer'],
            'attempt_times' => ['array', 'max:20'],
            'attempt_times.*' => ['date_format:H:i'],
            'attempts_over_limit_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'inquiries' => ['nullable', 'string', 'max:5000'],
            'issue_requests' => ['array', 'max:50'],
            'issue_requests.*.key' => ['required', 'string', 'max:40', 'distinct'],
            'issue_requests.*.store_id' => ['required', 'integer', 'exists:stores,id'],
            'issue_requests.*.type' => ['required', Rule::enum(IssueType::class)],
            'issue_requests.*.title' => ['required', 'string', 'max:200'],
            'issue_requests.*.description' => ['required', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            $max = ReportDraftService::maxAttempts();
            if (count($this->input('attempt_times', [])) > $max && ! filled($this->input('attempts_over_limit_reason'))) {
                $v->errors()->add('attempts_over_limit_reason', "الحد الأقصى {$max} محاولة في الشفت — اذكر سبب التجاوز.");
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'end_delivery_rate' => 'نسبة التسليم النهائية',
            'stores.*.store_id' => 'المتجر', 'stores.*.shipments_count' => 'عدد الأوردرات',
            'stores.*.start_orders' => 'طلبيات البداية', 'stores.*.start_rate' => 'نسبة البداية',
            'stores.*.codes.*.code' => 'الكود', 'stores.*.codes.*.result' => 'نتيجة الكود',
            'attempt_times.*' => 'وقت المحاولة', 'attempts_over_limit_reason' => 'سبب تجاوز المحاولات',
            'issue_requests.*.title' => 'عنوان المشكلة', 'issue_requests.*.description' => 'وصف المشكلة',
            'issue_requests.*.type' => 'نوع المشكلة', 'issue_requests.*.store_id' => 'متجر المشكلة',
        ];
    }

    public function messages(): array
    {
        return ['stores.*.store_id.exists' => 'المتجر غير موجود أو مؤرشف.', 'stores.*.store_id.distinct' => 'المتجر مضاف مرتين.'];
    }
}
