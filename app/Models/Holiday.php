<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** عطلة رسمية: لا تُحسب غياباً لأحد */
class Holiday extends Model
{
    use Auditable;

    protected $fillable = ['date', 'name', 'created_by'];

    protected $casts = ['date' => 'date'];
}
