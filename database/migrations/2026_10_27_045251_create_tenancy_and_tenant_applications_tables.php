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
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable()->unique();
            $table->string('logo_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('tenant_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('oauth_client_id')->nullable();
            $table->foreign('oauth_client_id')->references('id')->on('oauth_clients')->nullOnDelete();
            $table->string('name');
            $table->string('framework')->default('laravel'); // laravel, nextjs, vue, react, nodejs, vanilla
            $table->string('environment')->default('development'); // development, production
            $table->json('redirect_uris');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_applications');
        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');
    }
};
