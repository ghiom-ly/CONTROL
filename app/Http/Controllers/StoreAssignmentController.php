<?php

namespace App\Http\Controllers;

use App\Enums\StoreStatus;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** قائمة التعيين: كل موظف ومتاجره، والمتاجر غير المُسندة */
class StoreAssignmentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('stores.manage');
        $q = trim((string) $request->query('q', ''));

        $stores = Store::query()->where('status', '!=', StoreStatus::Archived)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")))
            ->orderBy('name')->get(['id', 'name', 'code', 'assigned_user_id']);

        $employees = self::employees()->get(['id', 'name']);

        return view('stores.assignment', [
            'employees' => $employees,
            'byUser' => $stores->groupBy(fn ($s) => $s->assigned_user_id ?? 0),
            'q' => $q,
        ]);
    }

    public function update(Request $request, StoreService $service): RedirectResponse
    {
        $this->authorize('stores.manage');
        $data = $request->validate([
            'store_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'store_ids.*' => ['integer', Rule::exists('stores', 'id')->where(fn ($q) => $q->where('status', '!=', StoreStatus::Archived->value)->whereNull('deleted_at'))],
            'user_id' => ['required', function ($attr, $v, $fail) {
                if ($v !== 'none' && ! self::employees()->whereKey($v)->exists()) {
                    $fail('اختر موظفاً نشطاً.');
                }
            }],
        ], ['store_ids.*.exists' => 'المتجر غير موجود أو مؤرشف.'], ['user_id' => 'الموظف', 'store_ids' => 'المتاجر']);

        $userId = $data['user_id'] === 'none' ? null : (int) $data['user_id'];
        DB::transaction(function () use ($data, $userId, $service, $request) {
            foreach (Store::whereIn('id', $data['store_ids'])->get() as $store) {
                if ($store->assigned_user_id !== $userId) {
                    $service->update($store, ['assigned_user_id' => $userId], $request->user());
                }
            }
        });

        return back()->with('success', 'تم تحديث إسناد '.count($data['store_ids']).' متجر.');
    }

    /** الموظفون النشطون بدور «موظف» فقط */
    public static function employees()
    {
        return User::query()->where('status', 'active')
            ->whereHas('role', fn ($r) => $r->where('name', Role::EMPLOYEE))->orderBy('name');
    }
}
