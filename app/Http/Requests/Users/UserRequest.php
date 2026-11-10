<?php

namespace App\Http\Requests\Users;

use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target ? $this->user()->can('update', $target) : $this->user()->can('create', \App\Models\User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => $this->filled('username') ? mb_strtolower(trim($this->input('username'))) : null,
            'email' => $this->filled('email') ? mb_strtolower(trim($this->input('email'))) : null,
            'phone' => $this->filled('phone') ? \App\Support\ArabicNormalizer::digits(trim($this->input('phone'))) : null,
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('user')?->id;
        $creating = $id === null;

        return [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($id)],
            'email' => ['nullable', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s().\/]{6,30}$/'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'work_type' => ['sometimes', 'required', Rule::in(['followup', 'confirmation'])],
            'base_salary' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'salary_effective_from' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'hired_at' => ['nullable', 'date', 'before_or_equal:today'],
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_effective_from' => ['nullable', 'date_format:Y-m-d'],
            'password' => [$creating ? 'required' : 'nullable', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'الاسم', 'username' => 'اسم المستخدم', 'email' => 'البريد الإلكتروني', 'phone' => 'رقم الهاتف',
            'role_id' => 'الدور الوظيفي', 'status' => 'الحالة', 'hired_at' => 'تاريخ التعيين', 'password' => 'كلمة المرور', 'shift_start' => 'وقت بداية الشفت', 'shift_effective_from' => 'يسري من',
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => 'اسم المستخدم يقبل الأحرف الإنجليزية الصغيرة والأرقام والنقطة والشرطة فقط.',
            'phone.regex' => 'رقم الهاتف غير صالح.',
        ];
    }
}
