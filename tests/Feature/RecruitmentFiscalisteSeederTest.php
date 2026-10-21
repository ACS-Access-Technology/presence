<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RecruitmentTest;
use App\Services\RecruitmentService;
use Database\Seeders\RecruitmentFiscalisteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecruitmentFiscalisteSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_seeder_cree_le_poste_fiscaliste_avec_18_questions(): void
    {
        (new RecruitmentFiscalisteSeeder)->run();

        $test = RecruitmentTest::where('slug', 'fiscaliste-groupe')->firstOrFail();
        $this->assertSame(18, $test->questions()->count());
        $this->assertSame(45, $test->duree_minutes);
    }

    public function test_relancer_le_seeder_ne_duplique_pas(): void
    {
        (new RecruitmentFiscalisteSeeder)->run();
        (new RecruitmentFiscalisteSeeder)->run();

        $this->assertSame(1, RecruitmentTest::where('slug', 'fiscaliste-groupe')->count());
    }

    public function test_une_candidature_parfaite_obtient_54_sur_54(): void
    {
        (new RecruitmentFiscalisteSeeder)->run();
        $test = RecruitmentTest::where('slug', 'fiscaliste-groupe')->firstOrFail();

        $recruitment = app(RecruitmentService::class);
        $candidature = $recruitment->startCandidature($test, [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'parfait@exemple.ci', 'telephone' => null,
        ]);

        foreach ($test->questions as $question) {
            $attendu = $question->reponse_attendue;
            $recruitment->saveReponse($candidature, $question, [
                'classement' => $attendu['classement'],
                'ajustement_tva' => $attendu['ajustement_tva'],
                'retenue_attendue' => $attendu['retenue_attendue'],
            ]);
        }

        $result = $recruitment->submit($candidature);

        $this->assertSame(54, $result->score);
    }
}
