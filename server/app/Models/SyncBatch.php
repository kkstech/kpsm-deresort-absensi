<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['batch_uuid', 'user_id', 'device_registration_id', 'event_count', 'summary', 'received_at'])]
class SyncBatch extends Model
{
    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'received_at' => 'datetime',
        ];
    }
}
