<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سجل رسالة فُتحت للإرسال عبر واتساب */
class StoreMessage extends Model
{
    protected $fillable = ['store_id', 'message_template_id', 'template_version', 'type', 'body', 'phone', 'sent_by', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id')->withTrashed();
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
