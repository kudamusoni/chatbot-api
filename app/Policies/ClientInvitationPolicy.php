<?php

namespace App\Policies;

use App\Models\ClientInvitation;
use App\Models\User;

class ClientInvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ClientInvitation $invitation): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function revoke(User $user, ClientInvitation $invitation): bool
    {
        return true;
    }
}

