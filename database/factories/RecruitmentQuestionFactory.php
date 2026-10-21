<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentQuestion>
 */
class RecruitmentQuestionFactory extends Factory
{
    protected $model = RecruitmentQuestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recruitment_test_id' => RecruitmentTest::factory(),
            'ordre' => fake()->unique()->numberBetween(1, 1000),
            'contexte' => [
                'id' => 'T'.fake()->unique()->numberBetween(1, 999),
                'entite' => 'Kôrô Technologies',
                'date' => '2026-06-03',
                'piece' => 'FV-601',
                'tiers' => 'Client Test',
                'nature' => 'Vente de licence',
                'montant_ht' => 1000000,
                'tva_comptabilisee' => 180000,
                'retenue_operee' => 0,
                'informations' => 'Contrat et facture disponibles',
            ],
            'reponse_attendue' => [
                'classement' => 'Conforme',
                'ajustement_tva' => 0,
                'retenue_attendue' => 0,
                'explication' => 'TVA collectée à rapprocher',
            ],
            'tolerance' => 0,
        ];
    }
}
