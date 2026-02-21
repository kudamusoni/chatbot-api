<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->isPlatformAdmin() || $user->hasAccessToClient($lead->client_id);
    }

    public function export(User $user): bool
    {
        return true;
    }

    public function exportReadonly(User $user): bool
    {
        return true;
    }
}
