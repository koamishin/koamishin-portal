<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class LaravelUserImporter
{
    /**
     * Import users from an array of records.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @param  array{default_role?: string, auto_verify?: bool, update_existing?: bool}  $options
     * @return array{total: int, imported: int, updated: int, skipped: int, errors: array<int, string>}
     */
    public function importFromData(
        array $records,
        Tenant $tenant,
        ?TenantApplication $application = null,
        string $environment = 'development',
        array $options = [],
    ): array {
        $defaultRoleName = $options['default_role'] ?? 'user';
        $autoVerify = (bool) ($options['auto_verify'] ?? false);
        $updateExisting = (bool) ($options['update_existing'] ?? true);

        $defaultRole = Role::query()->firstOrCreate([
            'name' => $defaultRoleName,
            'guard_name' => 'web',
        ]);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($records as $index => $row) {
            $email = trim((string) ($row['email'] ?? ''));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row #{$index}: Invalid or missing email address.";
                $skipped++;

                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $name = explode('@', $email)[0];
            }

            $rawPassword = (string) ($row['password'] ?? '');
            $passwordHash = $this->resolvePasswordHash($rawPassword);

            $emailVerifiedAt = null;
            if ($autoVerify) {
                $emailVerifiedAt = now();
            } elseif (! empty($row['email_verified_at'])) {
                try {
                    $emailVerifiedAt = Carbon::parse((string) $row['email_verified_at']);
                } catch (\Throwable) {
                    $emailVerifiedAt = now();
                }
            }

            /** @var User|null $existingUser */
            $existingUser = User::query()->where('email', $email)->first();

            try {
                if ($existingUser) {
                    if ($updateExisting) {
                        $updateData = ['name' => $name];
                        if ($autoVerify && $existingUser->email_verified_at === null) {
                            $updateData['email_verified_at'] = now();
                        }
                        $existingUser->update($updateData);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                    $user = $existingUser;
                } else {
                    /** @var User $user */
                    $user = User::query()->create([
                        'name' => $name,
                        'email' => $email,
                        'password' => $passwordHash,
                        'email_verified_at' => $emailVerifiedAt,
                    ]);

                    $user->assignRole($defaultRole);

                    WebhookDispatcher::dispatch('user.created', WebhookDispatcher::userPayload($user));
                    $imported++;
                }

                // 1. Link to Organization (Tenant)
                $tenant->users()->syncWithoutDetaching([
                    $user->id => ['role' => 'member'],
                ]);

                // 2. Link to Application with Environment
                if ($application instanceof \App\Models\TenantApplication) {
                    $application->users()->syncWithoutDetaching([
                        $user->id => [
                            'environment' => $environment,
                            'role' => $row['role'] ?? 'member',
                            'last_sign_in_at' => now(),
                        ],
                    ]);
                }
            } catch (\Throwable $e) {
                $errors[] = "Row #{$index} ({$email}): {$e->getMessage()}";
                $skipped++;
            }
        }

        return [
            'total' => count($records),
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Import users from a CSV string.
     *
     * @param  array{default_role?: string, auto_verify?: bool, update_existing?: bool}  $options
     * @return array{total: int, imported: int, updated: int, skipped: int, errors: array<int, string>}
     */
    public function importFromCsv(
        string $csvContent,
        Tenant $tenant,
        ?TenantApplication $application = null,
        string $environment = 'development',
        array $options = [],
    ): array {
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
        if (! is_array($lines) || $lines === []) {
            return ['total' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['CSV content is empty.']];
        }

        $delimiter = str_contains($lines[0], ';') ? ';' : (str_contains($lines[0], "\t") ? "\t" : ',');
        $header = str_getcsv(array_shift($lines), $delimiter);
        $header = array_map(fn ($col): string => strtolower(trim((string) $col)), $header);

        $records = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $row = str_getcsv($line, $delimiter);
            $record = [];
            foreach ($header as $i => $col) {
                $record[$col] = $row[$i] ?? null;
            }
            $records[] = $record;
        }

        return $this->importFromData($records, $tenant, $application, $environment, $options);
    }

    /**
     * Import users from a JSON string.
     *
     * @param  array{default_role?: string, auto_verify?: bool, update_existing?: bool}  $options
     * @return array{total: int, imported: int, updated: int, skipped: int, errors: array<int, string>}
     */
    public function importFromJson(
        string $jsonContent,
        Tenant $tenant,
        ?TenantApplication $application = null,
        string $environment = 'development',
        array $options = [],
    ): array {
        $decoded = json_decode($jsonContent, true);

        if (! is_array($decoded)) {
            return ['total' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Invalid JSON syntax.']];
        }

        /** @var array<int, array<string, mixed>> $records */
        $records = isset($decoded['users']) && is_array($decoded['users']) ? $decoded['users'] : (array_is_list($decoded) ? $decoded : [$decoded]);

        return $this->importFromData($records, $tenant, $application, $environment, $options);
    }

    /**
     * Import directly from another Laravel application database.
     *
     * @param  array{driver?: string, host?: string, port?: int|string, database: string, username?: string, password?: string, table?: string, limit?: int}  $dbConfig
     * @param  array{default_role?: string, auto_verify?: bool, update_existing?: bool}  $options
     * @return array{total: int, imported: int, updated: int, skipped: int, errors: array<int, string>}
     */
    public function importFromDatabase(
        array $dbConfig,
        Tenant $tenant,
        ?TenantApplication $application = null,
        string $environment = 'development',
        array $options = [],
    ): array {
        $driver = $dbConfig['driver'] ?? 'mysql';
        $connectionName = 'temp_external_import_'.Str::random(8);

        config(["database.connections.{$connectionName}" => [
            'driver' => $driver,
            'host' => $dbConfig['host'] ?? '127.0.0.1',
            'port' => (string) ($dbConfig['port'] ?? ($driver === 'pgsql' ? '5432' : '3306')),
            'database' => $dbConfig['database'],
            'username' => $dbConfig['username'] ?? '',
            'password' => $dbConfig['password'] ?? '',
            'charset' => 'utf8mb4',
            'prefix' => '',
        ]]);

        try {
            $tableName = $dbConfig['table'] ?? 'users';
            $limit = (int) ($dbConfig['limit'] ?? 1000);

            $rows = DB::connection($connectionName)
                ->table($tableName)
                ->limit($limit)
                ->get();

            $records = $rows->map(fn ($r): array => (array) $r)->toArray();

            return $this->importFromData($records, $tenant, $application, $environment, $options);
        } catch (\Throwable $e) {
            return [
                'total' => 0,
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['Database connection failed: '.$e->getMessage()],
            ];
        } finally {
            DB::purge($connectionName);
        }
    }

    /**
     * Handle Laravel Bcrypt/Argon2 password hash preservation or fallback hashing.
     */
    protected function resolvePasswordHash(string $password): string
    {
        $password = trim($password);

        if ($password === '') {
            return Hash::make(Str::random(32));
        }

        // Detect existing Laravel password hash format:
        // Bcrypt: $2y$..., $2a$..., $2b$... (60 chars)
        // Argon2: $argon2i$..., $argon2id$...
        if (
            str_starts_with($password, '$2y$') ||
            str_starts_with($password, '$2a$') ||
            str_starts_with($password, '$2b$') ||
            str_starts_with($password, '$argon2i$') ||
            str_starts_with($password, '$argon2id$')
        ) {
            // Keep the original hash verbatim!
            // Laravel Hash::check() will match it seamlessly when the user logs in.
            return $password;
        }

        // Otherwise plain-text password, hash it fresh with current default
        return Hash::make($password);
    }
}
