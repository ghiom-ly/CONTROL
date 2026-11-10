<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->guardRoleAssignment($actor, (int) $data['role_id']);

            $shift = self::pullShift($data);
            $salary = self::pullSalary($data, $actor);
            $user = User::create($data + [
                'created_by' => $actor->id,
                'must_change_password' => true,
            ]);
            if ($shift) {
                \App\Models\EmployeeShiftTime::set($user->id, $shift['time'], $shift['from'], $actor->id);
            }
            self::applySalary($user, $salary, $actor);

            return $user;
        });
    }

    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor) {
            if (isset($data['role_id']) && (int) $data['role_id'] !== $user->role_id) {
                $this->guardRoleAssignment($actor, (int) $data['role_id']);
                $this->guardLastSuperAdmin($user, 'لا يمكن تغيير دور آخر مدير عام في النظام.');
            }
            if (isset($data['status']) && $data['status'] !== 'active') {
                if ($user->is($actor)) {
                    throw ValidationException::withMessages(['status' => 'لا يمكنك إيقاف حسابك الشخصي.']);
                }
                $this->guardLastSuperAdmin($user, 'لا يمكن إيقاف آخر مدير عام نشط.');
            }
            if (empty($data['password'])) {
                unset($data['password']);
            }
            $shift = self::pullShift($data);
            $salary = self::pullSalary($data, $actor);
            $user->fill($data + ['updated_by' => $actor->id])->save();
            self::applySalary($user, $salary, $actor);
            if ($shift) {
                \App\Models\EmployeeShiftTime::set($user->id, $shift['time'], $shift['from'], $actor->id);
                \App\Services\Analytics\AnalyticsService::flush();
            }

            return $user;
        });
    }

    /** حقلا الراتب (ليسا أعمدة في users) — للمدير العام فقط، وإلا يُتجاهلان */
    private static function pullSalary(array &$data, User $actor): ?array
    {
        $sent = array_key_exists('base_salary', $data);
        $salary = $sent ? ['amount' => $data['base_salary'] === null || $data['base_salary'] === '' ? null : round((float) $data['base_salary'], 3),
            'from' => $data['salary_effective_from'] ?? now()->toDateString()] : null;
        unset($data['base_salary'], $data['salary_effective_from']);

        return $sent && $actor->isSuperAdmin() ? $salary : null;
    }

    /** المبلغ لا يُكتب في سجل العمليات */
    private static function applySalary(User $user, ?array $salary, User $actor): void
    {
        if ($salary && \App\Models\EmployeeSalary::set($user->id, $salary['amount'], $salary['from'], $actor->id)) {
            AuditLogger::log('salary_changed', 'تعديل الراتب الأساسي لـ '.$user->name.' (يسري من '.$salary['from'].')', $user);
        }
    }

    /** يُخرج حقلي الشفت من البيانات (ليسا أعمدة في users) — null إن لم يُرسلا */
    private static function pullShift(array &$data): ?array
    {
        if (! array_key_exists('shift_start', $data)) {
            unset($data['shift_effective_from']);

            return null;
        }
        $shift = ['time' => $data['shift_start'] ?: null, 'from' => $data['shift_effective_from'] ?? now()->toDateString()];
        unset($data['shift_start'], $data['shift_effective_from']);

        return $shift;
    }

    public function setStatus(User $user, string $status, User $actor): void
    {
        $this->update($user, ['status' => $status], $actor);
    }

    /** يعيد كلمة مرور مؤقتة يجب تغييرها عند أول دخول */
    public function resetPassword(User $user, User $actor, ?string $password = null): string
    {
        $password ??= Str::password(12, symbols: false);
        $user->forceFill(['password' => $password, 'must_change_password' => true, 'updated_by' => $actor->id])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        AuditLogger::model('password_reset', $user, [], [], "إعادة تعيين كلمة مرور {$user->username}");

        return $password;
    }

    public function delete(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => 'لا يمكنك حذف حسابك الشخصي.']);
        }
        $this->guardLastSuperAdmin($user, 'لا يمكن حذف آخر مدير عام.');
        DB::transaction(function () use ($user) {
            $user->forceFill(['status' => 'inactive'])->save();
            $user->delete(); // Soft delete — تبقى بيانات تقاريره وسجلاته محفوظة
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
    }

    /** المسؤول لا يستطيع منح دور المدير العام */
    private function guardRoleAssignment(User $actor, int $roleId): void
    {
        $role = Role::findOrFail($roleId);
        if ($role->name === Role::SUPER_ADMIN && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages(['role_id' => 'لا تملك صلاحية منح دور المدير العام.']);
        }
    }

    private function guardLastSuperAdmin(User $user, string $message): void
    {
        if (! $user->isSuperAdmin()) {
            return;
        }
        $others = User::whereHas('role', fn ($q) => $q->where('name', Role::SUPER_ADMIN))
            ->where('status', 'active')->whereKeyNot($user->id)->count();
        if ($others === 0) {
            throw ValidationException::withMessages(['role_id' => $message]);
        }
    }
}
