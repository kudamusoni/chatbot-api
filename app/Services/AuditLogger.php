<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogger
{
    public function log(User $actor, string $action, ?string $clientId = null, array $meta = []): AuditLog
    {
        return AuditLog::create([
            'actor_user_id' => $actor->id,
            'client_id' => $clientId,
            'action' => $action,
            'meta' => $meta,
        ]);
    }
}
