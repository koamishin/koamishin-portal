<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('tenant_applications')
            ->whereNotNull('oauth_client_id')
            ->whereIn('environment', ['development', 'production'])
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $application): void {
                $clientId = $application->oauth_client_id;

                if (DB::table('tenant_applications')->where('oauth_client_id', $clientId)->count() !== 1
                    || DB::table('tenant_application_environments')->where('oauth_client_id', $clientId)->exists()
                    || ! DB::table('oauth_clients')->where('id', $clientId)->where('revoked', false)->exists()) {
                    return;
                }

                $environment = DB::table('tenant_application_environments')
                    ->where('tenant_application_id', $application->id)
                    ->where('environment', $application->environment)
                    ->first();

                if ($environment !== null) {
                    if ($environment->oauth_client_id === null) {
                        DB::table('tenant_application_environments')
                            ->where('id', $environment->id)
                            ->whereNull('oauth_client_id')
                            ->update(['oauth_client_id' => $clientId, 'updated_at' => now()]);
                    }

                    return;
                }

                DB::table('tenant_application_environments')->insert([
                    'tenant_application_id' => $application->id,
                    'environment' => $application->environment,
                    'oauth_client_id' => $clientId,
                    'redirect_uris' => $application->redirect_uris,
                    'settings' => json_encode(new stdClass, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // A rollback cannot distinguish these bindings from later administrator changes.
    }
};
