<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('management_api_clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_application_environment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('oauth_client_id')->unique()->constrained('oauth_clients')->cascadeOnDelete();
            $table->json('allowed_scopes');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('management_api_clients');
    }
};
