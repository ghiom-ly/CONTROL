<?php

namespace App\Http\Requests\Stores;

use App\Enums\StoreStatus;
use App\Models\Store;
use App\Models\User;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('store');

        return $target ? $this->user()->can('update', $target) : $this->user()->can('create', Store::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->filled('name') ? trim(preg_replace('/\s+/u', ' ', $this->input('name'))) : null,
            'phone' => $this->filled('phone') ? ArabicNormalizer::digits(trim($this->input('phone'))) : null,
            'email' => $this->filled('email') ? mb_strtolower(trim($this->input('email'))) : null,
            'expected_avg_shipments' => $this->filled('expected_avg_shipments') ? ArabicNormalizer::digits((string) $this->input('expected_avg_shipments')) : null,
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('store')?->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:200', function ($attr, $value, $fail) use ($id) {
                $exists = Store::withTrashed()->where('normalized_name', ArabicNormalizer::normalize($value))
                    ->when($id, fn ($q) => $q->whereKeyNot($id))->exists();
                if ($exists) {
                    $fail('يوجد متجر مسجل بنفس الاسم.');
                }
            }],
            'owner_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s().\/]{6,30}$/'],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'started_at' => ['nullable', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::enum(StoreStatus::class)],
            'assigned_user_id' => ['nullable', 'integer', function ($attr, $value, $fail) {
                $user = User::with('role')->find($value);
                if (! $user || ! $user->isActive() || ! $user->hasPermission('reports.create')) {
                    $fail('يجب اختيار موظف نشط يمكنه إدخال التقارير.');
                }
            }],
            'expected_avg_shipments' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'اسم المتجر', 'owner_name' => 'اسم صاحب المتجر', 'phone' => 'رقم الهاتف', 'email' => 'البريد الإلكتروني',
            'started_at' => 'تاريخ بدء التعامل', 'status' => 'الحالة', 'assigned_user_id' => 'الموظف المسؤول',
            'expected_avg_shipments' => 'متوسط الشحنات المتوقع', 'notes' => 'ملاحظات',
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'رقم الهاتف غير صالح.'];
    }
}
