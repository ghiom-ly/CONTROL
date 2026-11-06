<?php

namespace App\Http\Controllers;

use App\Enums\UserStatus;
use App\Http\Requests\Users\UserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeePerformanceService;
use App\Services\UserService;
use App\Support\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'trashed' => ['nullable', 'boolean'],
        ]);

        $users = User::query()
            ->with('role:id,name,display_name')
            ->withCount(['dailyReports', 'assignedStores'])
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                    ->orWhere('username', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%"));
            })
            ->when($filters['role'] ?? null, fn ($q, $r) => $q->where('role_id', $r))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['trashed'] ?? false, fn ($q) => $q->onlyTrashed())
            ->orderBy('name')
            ->paginate(config('nours.per_page'))
            ->withQueryString();

        $counts = User::query()->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');

        return view('users.index', [
            'users' => $users,
            'roles' => $this->assignableRoles($request->user()),
            'allRoles' => Role::orderBy('id')->get(),
            'filters' => $filters,
            'counts' => $counts,
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = $this->users->create($request->validated(), $request->user());

        return redirect()->route('users.show', $user)->with('success', "تم إنشاء حساب الموظف {$user->name}. سيُطلب منه تغيير كلمة المرور عند أول دخول.");
    }

    public function show(Request $request, User $user, EmployeePerformanceService $performance): View
    {
        $this->authorize('view', $user);
        $period = Period::fromRequest($request, 'this_month');
        $user->load('role', 'creator:id,name');

        return view('users.show', [
            'user' => $user,
            'period' => $period,
            'metrics' => $performance->forUser($user, $period),
            'previous' => $performance->forUser($user, $period->previous()),
            'roles' => $this->assignableRoles($request->user()),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->users->update($user, $request->validated(), $request->user());

        return back()->with('success', 'تم حفظ بيانات الموظف.');
    }

    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);
        $new = $user->isActive() ? 'inactive' : 'active';
        $this->users->setStatus($user, $new, $request->user());

        return back()->with('success', $new === 'active' ? 'تم تفعيل الحساب.' : 'تم إيقاف الحساب وإنهاء جلساته.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);
        $password = $this->users->resetPassword($user, $request->user());

        return back()->with('temp_password', $password)
            ->with('success', 'تم إنشاء كلمة مرور مؤقتة. سلّمها للموظف — لن تظهر مرة أخرى.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);
        $this->users->delete($user, $request->user());

        return redirect()->route('users.index')->with('success', 'تم حذف الحساب (مع الاحتفاظ بسجلاته وتقاريره).');
    }

    /** حذف نهائي (المدير العام فقط، دور موظف أو مشاهد، بتأكيد كتابة اسم المستخدم) */
    public function purge(Request $request, int $id, \App\Services\UserPurgeService $purger): RedirectResponse
    {
        $user = User::withTrashed()->with('role')->findOrFail($id);
        abort_unless($request->user()->isSuperAdmin() && ! $request->user()->is($user)
            && $user->hasRole(Role::EMPLOYEE, Role::VIEWER), 403);
        $request->validate(['confirm' => ['required', 'string', \Illuminate\Validation\Rule::in([$user->username])]],
            ['confirm.in' => 'اكتب اسم المستخدم كما هو للتأكيد.', 'confirm.required' => 'اكتب اسم المستخدم للتأكيد.']);
        $purger->purge($user, $request->user());

        return redirect()->route('users.index')->with('success', "تم حذف {$user->name} نهائياً مع كل بياناته.");
    }

    public function restore(int $id): RedirectResponse
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $this->authorize('update', $user);
        $user->restore();

        return redirect()->route('users.show', $user)->with('success', 'تمت استعادة الحساب. الحساب غير نشط حتى تفعيله.');
    }

    private function assignableRoles(User $actor)
    {
        return Role::query()
            ->when(! $actor->isSuperAdmin(), fn ($q) => $q->where('name', '!=', Role::SUPER_ADMIN))
            ->orderBy('id')->get();
    }
}
