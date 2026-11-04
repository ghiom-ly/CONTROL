<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    protected $fillable = [
        'import_batch_id', 'row_number', 'raw_data', 'mapped_data', 'status', 'errors', 'warnings',
        'store_id', 'shipment_code', 'corrected_by',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'mapped_data' => 'array',
        'errors' => 'array',
        'warnings' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
