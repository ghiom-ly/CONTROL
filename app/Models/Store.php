<?php

namespace App\Models;

use App\Enums\StoreStatus;
use App\Models\Concerns\Auditable;
use App\Support\ArabicNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'owner_name', 'phone', 'email', 'started_at', 'status',
        'assigned_user_id', 'expected_avg_shipments', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'status' => StoreStatus::class,
        'started_at' => 'date',
        'expected_avg_shipments' => 'integer',
    ];

    protected static function booted(): void
    {
        // رقم مؤقت فريد حتى يُعرف المعرّف ثم يُستبدل بالرقم النهائي
        static::creating(function (self $model) {
            $model->code ??= 'TMP-'.\Illuminate\Support\Str::random(20);
        });
        static::saving(function (Store $store) {
            $store->normalized_name = ArabicNormalizer::normalize($store->name);
        });
        static::created(function (Store $store) {
            if (str_starts_with((string) $store->code, 'TMP-')) {
                $store->forceFill(['code' => sprintf('STR-%05d', $store->id)])->saveQuietly();
            }
        });
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function reportStores(): HasMany
    {
        return $this->hasMany(ReportStore::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function contactAttempts(): HasMany
    {
        return $this->hasMany(ContactAttempt::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(StoreDailyMetric::class);
    }
}
