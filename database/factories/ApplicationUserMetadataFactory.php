<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApplicationUserMetadata;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationUserMetadata>
 */
class ApplicationUserMetadataFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'public_metadata' => (object) [],
            'private_metadata' => (object) [],
        ];
    }
}
