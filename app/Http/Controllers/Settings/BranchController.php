<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\WhatsAppNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** بيانات الفروع: اسم الفرع، المسؤول ورقمه، مسؤول المناديب ورقمه */
class BranchController extends Controller
{
    public function index(): View
    {
        $this->authorize('settings.manage');

        return view('settings.branches', ['branches' => Branch::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');
        Branch::create($this->validated($request));

        return back()->with('success', 'تمت إضافة الفرع.');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorize('settings.manage');
        $branch->update($this->validated($request, $branch));

        return back()->with('success', 'تم حفظ بيانات الفرع.');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        $this->authorize('settings.manage');
        $branch->delete();

        return back()->with('success', 'تم حذف الفرع.');
    }

    private function validated(Request $request, ?Branch $branch = null): array
    {
        $phone = ['nullable', 'string', 'max:30', function ($attr, $value, $fail) {
            if (filled($value) && WhatsAppNumber::normalize($value) === null) {
                $fail('رقم الهاتف غير صحيح — اكتبه مثل 0912345678 أو بمفتاح الدولة.');
            }
        }];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('branches', 'name')->ignore($branch?->id)],
            'manager_name' => ['nullable', 'string', 'max:100'],
            'manager_phone' => $phone,
            'couriers_manager_name' => ['nullable', 'string', 'max:100'],
            'couriers_manager_phone' => $phone,
        ], [], [
            'name' => 'اسم الفرع', 'manager_name' => 'اسم المسؤول', 'manager_phone' => 'رقم المسؤول',
            'couriers_manager_name' => 'اسم مسؤول المناديب', 'couriers_manager_phone' => 'رقم مسؤول المناديب',
        ]);

        // الحقول غير المرسلة تُفرَّغ (نموذج التعديل يرسل كل الحقول)
        return array_merge(array_fill_keys(['manager_name', 'manager_phone', 'couriers_manager_name', 'couriers_manager_phone'], null), $data);
    }
}
