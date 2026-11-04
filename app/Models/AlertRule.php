<?php

namespace App\Models;

use App\Enums\AlertLevel;
use Illuminate\Database\Eloquent\Model;

class AlertRule extends Model
{
    protected $fillable = ['key', 'name', 'description', 'is_enabled', 'level', 'parameters', 'updated_by'];

    protected $casts = ['is_enabled' => 'boolean', 'level' => AlertLevel::class, 'parameters' => 'array'];
}
