<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_application_user', function (Blueprint $table): void {
            $table->dropUnique(['tenant_application_id', 'user_id']);
            $table->unique(['tenant_application_id', 'user_id', 'environment'], 'application_user_environment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('tenant_application_user')
            ->select(['tenant_application_id', 'user_id'])
            ->groupBy('tenant_application_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('Cannot roll back environment memberships while a user belongs to multiple environments.');
        }

        Schema::table('tenant_application_user', function (Blueprint $table): void {
            $table->dropUnique('application_user_environment_unique');
            $table->unique(['tenant_application_id', 'user_id']);
        });
    }
};
