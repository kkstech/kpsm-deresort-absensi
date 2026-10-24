<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function log(string $action, Model $subject, ?array $before = null, ?array $after = null): void
    {
        AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after ?? $subject->attributesToArray(),
            'request_id' => request()->attributes->get('request_id'),
        ]);
    }
}
