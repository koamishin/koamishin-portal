<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Models\Tenant;
use App\Models\TenantApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\ClientRepository;

class ApplicationProvisioner
{
    public function __construct(private readonly ClientRepository $clients) {}

    /**
     * @param  array{name: string, framework: string, environment: string, redirect_uris: array<int, string>, description?: ?string}  $data
     */
    public function create(Tenant $tenant, array $data): TenantApplication
    {
        $this->validateRedirects($data['environment'], $data['redirect_uris']);
        $confidential = in_array($data['framework'], ['laravel', 'nodejs'], true);

        [$application, $credentials] = DB::transaction(function () use ($tenant, $data, $confidential): array {
            $clients = [];
            foreach (['development', 'production'] as $environment) {
                $uris = $environment === $data['environment'] ? $data['redirect_uris'] : [];
                $clients[$environment] = $this->clients->createAuthorizationCodeGrantClient(
                    name: $data['name'].' ('.ucfirst($environment).')',
                    redirectUris: $uris,
                    confidential: $confidential,
                );
            }

            $selected = $clients[$data['environment']];
            $application = $tenant->applications()->create([
                'name' => $data['name'],
                'framework' => $data['framework'],
                'environment' => $data['environment'],
                'redirect_uris' => $data['redirect_uris'],
                'description' => $data['description'] ?? null,
                'oauth_client_id' => (string) $selected->getKey(),
            ]);

            $credentials = [];
            foreach ($clients as $environment => $client) {
                $application->environments()->create([
                    'environment' => $environment,
                    'oauth_client_id' => (string) $client->getKey(),
                    'redirect_uris' => $environment === $data['environment'] ? $data['redirect_uris'] : [],
                    'settings' => [],
                ]);

                $credentials[$environment] = [
                    'client_id' => (string) $client->getKey(),
                    'secret' => $client->plainSecret,
                ];
            }

            return [$application, $credentials];
        });

        session()->flash('new_app_credentials', $credentials);

        return $application;
    }

    /**
     * @param  array<int, mixed>  $redirectUris
     */
    public function validateRedirects(string $environment, array $redirectUris): void
    {
        if (! in_array($environment, ['development', 'production'], true) || $redirectUris === []) {
            throw ValidationException::withMessages(['redirect_uris' => 'Provide at least one callback URL for the selected environment.']);
        }

        foreach ($redirectUris as $uri) {
            $parts = is_string($uri) ? parse_url($uri) : false;
            if (! is_array($parts) || ! filter_var($uri, FILTER_VALIDATE_URL)
                || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
                || ($environment === 'production' && $parts['scheme'] !== 'https')) {
                throw ValidationException::withMessages(['redirect_uris' => 'Use valid callback URLs without credentials or fragments. Production callbacks must use HTTPS.']);
            }
        }
    }
}
