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

            return User::create($data + [
                'created_by' => $actor->id,
                'must_change_password' => true,
            ]);
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
            $user->fill($data + ['updated_by' => $actor->id])->save();

            return $user;
        });
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
