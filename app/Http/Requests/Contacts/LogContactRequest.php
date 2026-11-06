<?php

namespace App\Http\Requests\Contacts;

use App\Enums\ContactResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** مسار الكود: الزبون أو المندوب فقط. مسار المتجر: الطرف ثابت (المتجر) */
class LogContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // يُفحص في المتحكم حسب المسار
    }

    public function rules(): array
    {
        return [
            'party_type' => [$this->route('store') ? 'prohibited' : 'required', Rule::in(['customer', 'courier'])],
            'result' => ['required', Rule::enum(ContactResult::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return ['party_type.in' => 'الموظف يسجّل الاتصال بالزبون أو المندوب فقط — الاتصال بالمتجر من صفحة المتجر للإدارة.'];
    }
}
