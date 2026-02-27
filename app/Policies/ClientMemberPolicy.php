<?php

namespace App\Policies;

use App\Models\User;

class ClientMemberPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function updateRole(User $user): bool
    {
        return true;
    }

    public function remove(User $user): bool
    {
        return true;
    }
}

