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
        // 1. Environments table for per-environment application configurations
        Schema::create('tenant_application_environments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_application_id')->constrained('tenant_applications')->cascadeOnDelete();
            $table->string('environment'); // development, production
            $table->string('oauth_client_id')->nullable();
            $table->foreign('oauth_client_id')->references('id')->on('oauth_clients')->nullOnDelete();
            $table->json('redirect_uris');
            $table->json('settings')->nullable(); // allow_signups, require_email_verification, allowed_social_providers, etc.
            $table->timestamps();

            $table->unique(['tenant_application_id', 'environment']);
        });

        // 2. Add environment column to tenant_application_user
        Schema::table('tenant_application_user', function (Blueprint $table): void {
            $table->string('environment')->default('development')->after('user_id');
            $table->index(['tenant_application_id', 'environment']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_application_user', function (Blueprint $table): void {
            $table->dropIndex(['tenant_application_id', 'environment']);
            $table->dropColumn('environment');
        });

        Schema::dropIfExists('tenant_application_environments');
    }
};
