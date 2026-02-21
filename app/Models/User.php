<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'platform_role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->platform_role === 'super_admin';
    }

    public function isSupportAdmin(): bool
    {
        return $this->platform_role === 'support_admin';
    }

    public function isPlatformAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->isSupportAdmin();
    }

    /**
     * Get the clients this user belongs to.
     */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Check if the user has access to a specific client.
     */
    public function hasAccessToClient(string $clientId): bool
    {
        return $this->clients()->where('clients.id', $clientId)->exists();
    }

    /**
     * Get the user's role for a specific client.
     */
    public function roleForClient(string $clientId): ?string
    {
        $client = $this->clients()->where('clients.id', $clientId)->first();
        $role = $client?->pivot?->role;

        return $role === 'member' ? 'viewer' : $role;
    }

    public function hasClientRole(string $clientId, array $roles): bool
    {
        $role = $this->roleForClient($clientId);

        return $role !== null && in_array($role, $roles, true);
    }

    public function canManageClient(string $clientId): bool
    {
        return $this->hasClientRole($clientId, ['owner', 'admin']);
    }
}
