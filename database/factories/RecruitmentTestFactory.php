<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Filiale;
use App\Models\RecruitmentTest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecruitmentTest>
 */
class RecruitmentTestFactory extends Factory
{
    protected $model = RecruitmentTest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $titre = 'Poste '.fake()->unique()->jobTitle();

        return [
            'filiale_id' => Filiale::factory(),
            'titre' => $titre,
            'slug' => Str::slug($titre).'-'.Str::lower(Str::random(4)),
            'duree_minutes' => 45,
            'instructions' => 'Répondez aux 18 transactions ci-dessous.',
            'actif' => true,
        ];
    }
}
