<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PersonSource;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => Person::normalizeEmail(fake()->unique()->safeEmail()),
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'phone' => null,
            'company' => null,
            'direction' => null,
            'service' => null,
            'position' => null,
            'is_staff' => true,
            'source' => PersonSource::Import,
        ];
    }

    /** Visiteur non-personnel (émargement public). */
    public function visitor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_staff' => false,
            'source' => PersonSource::Emargement,
        ]);
    }
}
