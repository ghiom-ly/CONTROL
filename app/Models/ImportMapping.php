<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** مطابقة أعمدة محفوظة لشكل ملف (يُعرف بتوقيع عناوينه) */
class ImportMapping extends Model
{
    protected $fillable = ['signature', 'layout', 'columns', 'delivered_values', 'created_by'];

    protected $casts = ['columns' => 'array', 'delivered_values' => 'array'];

    /** @return array{layout: string, columns: array, delivered_values: array} */
    public function toMapping(): array
    {
        return ['layout' => $this->layout, 'columns' => $this->columns, 'delivered_values' => $this->delivered_values ?? []];
    }
}
