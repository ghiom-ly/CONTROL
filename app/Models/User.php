<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Auditable, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'username', 'email', 'phone', 'password', 'role_id', 'work_type', 'status',
        'hired_at', 'must_change_password', 'created_by', 'updated_by',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $auditExclude = ['last_login_at', 'last_login_ip'];

    /** كاش الصلاحيات داخل الطلب الواحد */
    protected ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => UserStatus::class,
            'hired_at' => 'date',
            'last_login_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dailyReports(): HasMany
    {
        return $this->hasMany(DailyReport::class);
    }

    public function assignedStores(): HasMany
    {
        return $this->hasMany(Store::class, 'assigned_user_id');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(ShipmentFollowup::class);
    }

    public function contactAttempts(): HasMany
    {
        return $this->hasMany(ContactAttempt::class);
    }

    public function reportedIssues(): HasMany
    {
        return $this->hasMany(Issue::class, 'reported_by');
    }

    public function assignedIssues(): HasMany
    {
        return $this->hasMany(Issue::class, 'assigned_to');
    }

    // ---------------------------------------------------------------- صلاحيات

    /** موظفة تأكيد طلبيات (قالب تقرير التأكيد) */
    public function isConfirmation(): bool
    {
        return $this->work_type === 'confirmation';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->name === Role::SUPER_ADMIN;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role?->name, $roles, true);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function permissionNames(): array
    {
        if ($this->permissionCache === null) {
            $this->permissionCache = $this->role
                ? $this->role->permissions()->pluck('name')->all()
                : [];
        }

        return $this->permissionCache;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->isSuperAdmin() || in_array($permission, $this->permissionNames(), true);
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
    }

    public function initials(): string
    {
        // تجاهل «ال» التعريف حتى لا تظهر الأحرف الأولى «اا»
        $parts = array_map(fn ($w) => mb_strlen($w) > 3 && str_starts_with($w, 'ال') ? mb_substr($w, 2) : $w,
            preg_split('/\s+/u', trim($this->name)));

        return mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1);
    }
}
