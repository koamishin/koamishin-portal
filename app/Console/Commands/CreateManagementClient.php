<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ManagementApiClient;
use App\Models\TenantApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

class CreateManagementClient extends Command
{
    protected $signature = 'koamishin:management-client
        {application : Tenant application ID}
        {environment : development or production}
        {--scope=* : An explicitly granted management scope}
        {--name= : A name for the client}';

    protected $description = 'Create a scoped management client for one application environment.';

    public function handle(ClientRepository $clients): int
    {
        $environmentName = (string) $this->argument('environment');
        $scopes = array_values(array_unique($this->option('scope')));

        if (! in_array($environmentName, ['development', 'production'], true)) {
            $this->components->error('Environment must be development or production.');

            return self::FAILURE;
        }

        if ($scopes === [] || array_diff($scopes, ManagementApiClient::SCOPES) !== []) {
            $this->components->error('Specify one or more valid --scope values: '.implode(', ', ManagementApiClient::SCOPES));

            return self::FAILURE;
        }

        $application = TenantApplication::query()->with('tenant')->find((string) $this->argument('application'));
        $environment = $application?->environments()->where('environment', $environmentName)->first();

        if (! $application || ! $application->tenant->is_active || ! $environment) {
            $this->components->error('Active organization, application, and existing environment are required.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: "{$application->name} ({$environmentName}) management"));

        if ($name === '' || mb_strlen($name) > 255) {
            $this->components->error('Client name must contain 1 to 255 characters.');

            return self::FAILURE;
        }

        /** @var Client $client */
        $client = DB::transaction(function () use ($clients, $environment, $name, $scopes): Client {
            $client = $clients->createClientCredentialsGrantClient($name);

            ManagementApiClient::query()->create([
                'tenant_application_environment_id' => $environment->getKey(),
                'oauth_client_id' => $client->getKey(),
                'allowed_scopes' => $scopes,
                'is_active' => true,
            ]);

            return $client;
        });

        $this->components->info('Management client created. Store its secret securely; it will not be shown again.');
        $this->components->twoColumnDetail('Client ID', (string) $client->getKey());
        $this->components->twoColumnDetail('Client secret', (string) $client->plainSecret);
        $this->components->twoColumnDetail('Granted scopes', implode(' ', $scopes));

        return self::SUCCESS;
    }
}
