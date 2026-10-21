<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Filiale;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Partner> */
class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'filiale_id' => Filiale::factory(),
            'name' => 'Direction générale '.fake()->unique()->word(),
            'logo_path' => 'partners/logo.png',
            'primary_color' => '#0b5e3c',
            'secondary_color' => '#f2a900',
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
        ];
    }
}
