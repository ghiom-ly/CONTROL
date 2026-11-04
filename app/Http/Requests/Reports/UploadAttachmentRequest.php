<?php

namespace App\Http\Requests\Reports;

use App\Models\Attachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('report'));
    }

    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(array_keys(Attachment::REPORT_CATEGORIES))],
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('nours.uploads.max_size_kb')],
        ];
    }

    public function attributes(): array
    {
        return ['category' => 'نوع السكرين', 'files' => 'الصور', 'files.*' => 'الصورة'];
    }

    public function messages(): array
    {
        return ['files.*.mimes' => 'يُسمح بالصور فقط (jpg, png, webp).'];
    }
}
