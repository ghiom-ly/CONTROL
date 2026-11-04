<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** تنبيه حضور أُرسل (يمنع تكرار نفس التنبيه في نفس اليوم) */
class AttendanceAlert extends Model
{
    protected $fillable = ['user_id', 'alert_date', 'kind'];

    protected $casts = ['alert_date' => 'date'];
}
