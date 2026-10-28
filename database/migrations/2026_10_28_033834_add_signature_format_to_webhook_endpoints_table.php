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
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->string('signature_format')->default('legacy');
            $table->foreignId('tenant_application_environment_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->string('message_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['message_id']);
            $table->dropColumn('message_id');
        });

        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tenant_application_environment_id');
            $table->dropColumn('signature_format');
        });
    }
};
