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
        Schema::create('application_user_metadata', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_application_environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('public_metadata')->nullable();
            $table->json('private_metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_application_environment_id', 'user_id'], 'application_user_metadata_environment_user_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_user_metadata');
    }
};
