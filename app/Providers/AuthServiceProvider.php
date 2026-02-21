<?php

namespace App\Providers;

use App\Models\Lead;
use App\Policies\LeadPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Lead::class => LeadPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Gate::before(function ($user, string $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }

            if ($user->isSupportAdmin()) {
                return in_array($ability, ['viewAny', 'view', 'export', 'export_readonly'], true);
            }

            return null;
        });
    }
}
