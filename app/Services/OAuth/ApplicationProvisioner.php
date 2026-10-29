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
     * @param  array<string, mixed>  $data
     */
    public function create(Tenant $tenant, array $data): TenantApplication
    {
        $name = $data['name'] ?? null;
        $framework = $data['framework'] ?? null;
        $environment = $data['environment'] ?? null;
        $inputRedirectUris = $data['redirect_uris'] ?? null;
        $description = $data['description'] ?? null;

        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'Provide an application name of at most 255 characters.']);
        }

        if (! is_string($framework) || trim($framework) === '') {
            throw ValidationException::withMessages(['framework' => 'Select an application framework.']);
        }

        if (! is_string($environment) || ! is_array($inputRedirectUris)) {
            throw ValidationException::withMessages(['redirect_uris' => 'Provide an environment and callback URLs.']);
        }

        if ($description !== null && ! is_string($description)) {
            throw ValidationException::withMessages(['description' => 'The description must be text.']);
        }

        $allowSignups = $data['allow_signups'] ?? ($environment === 'development');
        if (! is_bool($allowSignups)) {
            throw ValidationException::withMessages(['allow_signups' => 'Choose whether self-service signup is allowed.']);
        }

        $redirectUris = [];
        foreach ($inputRedirectUris as $uri) {
            if (! is_string($uri)) {
                throw ValidationException::withMessages(['redirect_uris' => 'Each callback URL must be text.']);
            }

            $redirectUris[] = $uri;
        }

        $data = [
            'name' => $name,
            'framework' => $framework,
            'environment' => $environment,
            'redirect_uris' => $redirectUris,
            'description' => $description,
            'allow_signups' => $allowSignups,
        ];

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
                'description' => $data['description'],
                'oauth_client_id' => (string) $selected->getKey(),
            ]);

            $credentials = [];
            foreach ($clients as $environment => $client) {
                $application->environments()->create([
                    'environment' => $environment,
                    'oauth_client_id' => (string) $client->getKey(),
                    'redirect_uris' => $environment === $data['environment'] ? $data['redirect_uris'] : [],
                    'settings' => [
                        'allow_signups' => $environment === $data['environment']
                            ? $data['allow_signups']
                            : $environment === 'development',
                    ],
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
