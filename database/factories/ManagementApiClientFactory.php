<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ManagementApiClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagementApiClient>
 */
class ManagementApiClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'allowed_scopes' => ['users:read'],
            'is_active' => true,
        ];
    }
}
