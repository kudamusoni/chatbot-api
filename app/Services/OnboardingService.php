<?php

namespace App\Services;

use App\Models\Client;
use App\Models\AppraisalQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OnboardingService
{
    /**
     * @param array{name:string,email:string,password:string,company_name:string} $input
     * @return array{user:User,client:Client}
     */
    public function registerOwnerAndClient(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $normalizedEmail = $this->normalizeEmail($input['email']);

            $existing = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($normalizedEmail)])->exists();
            if ($existing) {
                throw new \RuntimeException('EMAIL_TAKEN');
            }

            $user = User::create([
                'name' => $input['name'],
                'email' => $normalizedEmail,
                'password' => $input['password'],
                'platform_role' => 'none',
            ]);

            $client = Client::create([
                'name' => $input['company_name'],
                'slug' => $this->uniqueClientSlug($input['company_name']),
                'settings' => [],
            ]);

            $client->users()->attach($user->id, ['role' => 'owner']);
            AppraisalQuestion::ensureDefaultsForClient((string) $client->id);

            return [
                'user' => $user,
                'client' => $client,
            ];
        });
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function uniqueClientSlug(string $companyName): string
    {
        $base = Str::slug($companyName);
        $base = $base !== '' ? $base : 'client';
        $slug = $base;
        $suffix = 2;

        while (Client::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
