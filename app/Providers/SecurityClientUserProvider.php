<?php

namespace App\Providers;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\DB;

/**
 * Authenticates through PostgreSQL security-definer functions so the runtime
 * application role never reads identity.users or its password hashes.
 */
final class SecurityClientUserProvider implements UserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        if (! is_string($identifier) || ! TenantContext::isValidUuid($identifier)) {
            return null;
        }

        $row = DB::selectOne('SELECT * FROM security.load_client_user(?)', [$identifier]);

        return $row ? $this->makeUser($row) : null;
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        // Persistent "remember me" cookies are disabled until their token
        // lifecycle has a separate security-definer implementation.
        return null;
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        // The sign-in endpoint never enables remember-me cookies.
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        $email = $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;

        if (! is_string($email) || ! is_string($password) || $email === '' || $password === '') {
            return null;
        }

        $row = DB::selectOne('SELECT * FROM security.authenticate_client_user(?, ?)', [$email, $password]);

        if (! $row) {
            return null;
        }

        $user = $this->makeUser($row);
        $user->credentialsVerified = true;

        return $user;
    }

    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        return $user instanceof User && $user->credentialsVerified;
    }

    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false): void
    {
        // Rehashing must be implemented through a database security-definer
        // function before Laravel's automatic password upgrade is enabled.
    }

    private function makeUser(object $row): User
    {
        $user = new User;
        $user->setRawAttributes([
            'id' => $row->id,
            'name' => $row->name,
            'email' => $row->email,
        ], sync: true);
        $user->exists = true;
        $user->requiresMfa = $this->databaseBoolean($row->requires_mfa ?? false);
        $user->mfaEnabled = $this->databaseBoolean($row->mfa_enabled ?? false);
        $user->mfaSecretCiphertext = $row->mfa_secret_ciphertext ?? null;
        $user->authVersion = (int) ($row->auth_version ?? 1);

        return $user;
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === '1' || $value === 1;
    }
}
