<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** فرع شركة التوصيل: مسؤول الفرع ومسؤول المناديب وأرقامهم */
class Branch extends Model
{
    use Auditable;

    protected $fillable = ['name', 'manager_name', 'manager_phone', 'couriers_manager_name', 'couriers_manager_phone'];
}
