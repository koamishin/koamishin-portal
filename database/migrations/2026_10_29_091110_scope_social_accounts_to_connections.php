<?php

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
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_id']);
            $table->dropUnique(['user_id', 'provider']);
            $table->string('connection_scope', 100)->default('portal');
            $table->unique(['provider', 'provider_id', 'connection_scope'], 'social_accounts_provider_identity_scope_unique');
            $table->unique(['user_id', 'provider', 'connection_scope'], 'social_accounts_user_provider_scope_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $duplicateProviderIdentities = DB::table('social_accounts')->select(['provider', 'provider_id'])
            ->groupBy('provider', 'provider_id')->havingRaw('COUNT(*) > 1')->exists();
        $duplicateUserProviders = DB::table('social_accounts')->select(['user_id', 'provider'])
            ->groupBy('user_id', 'provider')->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicateProviderIdentities || $duplicateUserProviders) {
            throw new RuntimeException('Cannot remove social connection scopes while accounts overlap across connections.');
        }

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropUnique('social_accounts_provider_identity_scope_unique');
            $table->dropUnique('social_accounts_user_provider_scope_unique');
            $table->dropColumn('connection_scope');
            $table->unique(['provider', 'provider_id']);
            $table->unique(['user_id', 'provider']);
        });
    }
};
