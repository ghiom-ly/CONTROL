<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** اسم بديل للمتجر كما يظهر في ملفات المنظومة أو التقارير القديمة */
class StoreAlias extends Model
{
    use Auditable;

    protected $fillable = ['store_id', 'alias', 'normalized_alias', 'created_by'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
