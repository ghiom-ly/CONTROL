<?php

namespace App\Http\Requests\Reports;

use App\Enums\StoreStatus;
use App\Models\DailyReport;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** بدء الشفت: لكل متجر طلبياته ونسبة تسليمه عند البداية */
class StartShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', DailyReport::class);
    }

    protected function prepareForValidation(): void
    {
        $num = fn ($v) => ($v === null || trim((string) $v) === '') ? null
            : str_replace(['٫', ',', '%', '٪', ' '], ['.', '.', '', '', ''], ArabicNormalizer::digits((string) $v));

        $rows = collect($this->input('stores', []))
            ->filter(fn ($r) => is_array($r) && ! empty($r['store_id']))
            ->map(fn ($r) => ['store_id' => $r['store_id'], 'start_orders' => $num($r['start_orders'] ?? null), 'start_rate' => $num($r['start_rate'] ?? null)])
            ->filter(fn ($r) => $r['start_orders'] !== null || $r['start_rate'] !== null)
            ->values()->all();

        $this->merge(['stores' => $rows]);
    }

    public function rules(): array
    {
        // موظفة التأكيد تبدأ الشفت بلا طلبيات ونسب بداية
        if ($this->user()->isConfirmation()) {
            return ['stores' => ['array', 'max:0']];
        }

        return [
            'stores' => ['required', 'array', 'min:1', 'max:200'],
            'stores.*.store_id' => ['required', 'integer', 'distinct',
                Rule::exists('stores', 'id')->where(fn ($q) => $q->where('status', '!=', StoreStatus::Archived->value)->whereNull('deleted_at')->whereIn('service_type', ['both', 'followup']))],
            'stores.*.start_orders' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'stores.*.start_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return ['stores.required' => 'أدخل طلبيات ونسبة تسليم متجر واحد على الأقل.', 'stores.min' => 'أدخل طلبيات ونسبة تسليم متجر واحد على الأقل.'];
    }

    public function attributes(): array
    {
        return ['stores.*.start_orders' => 'طلبيات المتجر', 'stores.*.start_rate' => 'نسبة التسليم', 'stores.*.store_id' => 'المتجر'];
    }
}
