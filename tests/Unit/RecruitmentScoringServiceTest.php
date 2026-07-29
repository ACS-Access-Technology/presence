<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\RecruitmentCandidatureStatus;
use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentReponse;
use App\Models\RecruitmentTest;
use App\Services\RecruitmentScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecruitmentScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecruitmentScoringService $scoring;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoring = new RecruitmentScoringService;
    }

    private function question(array $reponseAttendue, int $tolerance = 0): RecruitmentQuestion
    {
        return RecruitmentQuestion::factory()->create([
            'reponse_attendue' => $reponseAttendue,
            'tolerance' => $tolerance,
        ]);
    }

    public function test_une_reponse_totalement_correcte_vaut_3_points(): void
    {
        $question = $this->question(['classement' => 'Majeur', 'ajustement_tva' => -168000, 'retenue_attendue' => 0, 'explication' => '']);
        $reponse = new RecruitmentReponse([
            'classement_saisi' => 'Majeur', 'ajustement_tva_saisi' => -168000, 'retenue_saisie' => 0,
        ]);

        $result = $this->scoring->scoreReponse($question, $reponse);

        $this->assertSame(['classement' => true, 'ajustement_tva' => true, 'retenue_attendue' => true, 'points' => 3], $result);
    }

    public function test_une_reponse_vide_vaut_0_point(): void
    {
        $question = $this->question(['classement' => 'Majeur', 'ajustement_tva' => -168000, 'retenue_attendue' => 0, 'explication' => '']);
        $reponse = new RecruitmentReponse;

        $result = $this->scoring->scoreReponse($question, $reponse);

        $this->assertSame(0, $result['points']);
    }

    public function test_la_tolerance_numerique_est_appliquee(): void
    {
        $question = $this->question(['classement' => 'Majeur', 'ajustement_tva' => -168000, 'retenue_attendue' => 0, 'explication' => ''], tolerance: 500);
        $reponse = new RecruitmentReponse([
            'classement_saisi' => 'Majeur', 'ajustement_tva_saisi' => -168300, 'retenue_saisie' => 0,
        ]);

        $result = $this->scoring->scoreReponse($question, $reponse);

        $this->assertTrue($result['ajustement_tva']);
    }

    public function test_score_candidature_additionne_les_points_de_toutes_les_reponses(): void
    {
        $test = RecruitmentTest::factory()->create();
        $q1 = RecruitmentQuestion::factory()->for($test)->create([
            'reponse_attendue' => ['classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0, 'explication' => ''],
        ]);
        $q2 = RecruitmentQuestion::factory()->for($test)->create([
            'reponse_attendue' => ['classement' => 'Critique', 'ajustement_tva' => 0, 'retenue_attendue' => 1960000, 'explication' => ''],
        ]);
        $candidature = RecruitmentCandidature::create([
            'recruitment_test_id' => $test->id, 'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'x@exemple.ci',
            'token' => bin2hex(random_bytes(20)), 'started_at' => Carbon::now(), 'expires_at' => Carbon::now()->addMinutes(45),
            'statut' => RecruitmentCandidatureStatus::EnCours,
        ]);
        RecruitmentReponse::create([
            'recruitment_candidature_id' => $candidature->id, 'recruitment_question_id' => $q1->id,
            'classement_saisi' => 'Conforme', 'ajustement_tva_saisi' => 0, 'retenue_saisie' => 0,
        ]);
        RecruitmentReponse::create([
            'recruitment_candidature_id' => $candidature->id, 'recruitment_question_id' => $q2->id,
            'classement_saisi' => 'Critique', 'ajustement_tva_saisi' => 0, 'retenue_saisie' => 0, // retenue fausse
        ]);

        $this->assertSame(5, $this->scoring->scoreCandidature($candidature)); // 3 + 2
    }
}
