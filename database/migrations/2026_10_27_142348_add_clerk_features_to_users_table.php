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
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->unique()->after('email');
            $table->string('phone_number')->nullable()->unique()->after('username');
            $table->timestamp('phone_verified_at')->nullable()->after('phone_number');
            $table->string('web3_wallet')->nullable()->after('phone_verified_at');
            $table->json('metadata')->nullable()->after('ban_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'username',
                'phone_number',
                'phone_verified_at',
                'web3_wallet',
                'metadata',
            ]);
        });
    }
};
