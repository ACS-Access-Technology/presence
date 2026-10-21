<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RecruitmentCandidatureStatus;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentTest;
use App\Services\RecruitmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RecruitmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecruitmentService $recruitment;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->recruitment = app(RecruitmentService::class);
    }

    private function identite(string $email = 'candidat@exemple.ci'): array
    {
        return ['nom' => 'Doe', 'prenom' => 'Jane', 'email' => $email, 'telephone' => null];
    }

    public function test_demarrer_une_candidature_pose_started_at_et_expires_at(): void
    {
        $test = RecruitmentTest::factory()->create(['duree_minutes' => 45]);

        $candidature = $this->recruitment->startCandidature($test, $this->identite());

        $this->assertNotNull($candidature);
        $this->assertSame(RecruitmentCandidatureStatus::EnCours, $candidature->statut);
        $this->assertEqualsWithDelta(45, $candidature->started_at->diffInMinutes($candidature->expires_at), 0.01);
    }

    public function test_un_meme_email_ne_peut_pas_redemarrer_le_meme_test(): void
    {
        $test = RecruitmentTest::factory()->create();
        $this->recruitment->startCandidature($test, $this->identite());

        $second = $this->recruitment->startCandidature($test, $this->identite());

        $this->assertNull($second);
        $this->assertSame(1, $test->candidatures()->count());
    }

    public function test_sauvegarder_une_reponse_pendant_le_delai(): void
    {
        $test = RecruitmentTest::factory()->create();
        $question = RecruitmentQuestion::factory()->for($test)->create();
        $candidature = $this->recruitment->startCandidature($test, $this->identite());

        $reponse = $this->recruitment->saveReponse($candidature, $question, [
            'classement' => 'Majeur', 'ajustement_tva' => -168000, 'retenue_attendue' => 0,
        ]);

        $this->assertNotNull($reponse);
        $this->assertSame('Majeur', $reponse->classement_saisi);
    }

    public function test_sauvegarder_une_reponse_apres_expiration_echoue(): void
    {
        $test = RecruitmentTest::factory()->create();
        $question = RecruitmentQuestion::factory()->for($test)->create();
        $candidature = $this->recruitment->startCandidature($test, $this->identite());
        $candidature->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

        $reponse = $this->recruitment->saveReponse($candidature, $question, ['classement' => 'Majeur']);

        $this->assertNull($reponse);
    }

    public function test_soumettre_calcule_le_score_et_marque_soumis(): void
    {
        $test = RecruitmentTest::factory()->create();
        $question = RecruitmentQuestion::factory()->for($test)->create([
            'reponse_attendue' => ['classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0, 'explication' => ''],
        ]);
        $candidature = $this->recruitment->startCandidature($test, $this->identite());
        $this->recruitment->saveReponse($candidature, $question, [
            'classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0,
        ]);

        $result = $this->recruitment->submit($candidature);

        $this->assertNotNull($result);
        $this->assertSame(RecruitmentCandidatureStatus::Soumis, $result->statut);
        $this->assertSame(3, $result->score);
        $this->assertNotNull($result->submitted_at);
    }

    public function test_soumettre_deux_fois_echoue_la_seconde_fois(): void
    {
        $test = RecruitmentTest::factory()->create();
        $candidature = $this->recruitment->startCandidature($test, $this->identite());
        $this->recruitment->submit($candidature);

        $second = $this->recruitment->submit($candidature->fresh());

        $this->assertNull($second);
    }

    public function test_le_premier_chargement_de_la_page_de_test_reussit(): void
    {
        $test = RecruitmentTest::factory()->create();
        $candidature = $this->recruitment->startCandidature($test, $this->identite());

        $result = $this->recruitment->registerPageOpen($candidature);

        $this->assertTrue($result);
        $this->assertNotNull($candidature->fresh()->opened_at);
        $this->assertSame(RecruitmentCandidatureStatus::EnCours, $candidature->fresh()->statut);
    }

    public function test_recharger_la_page_de_test_abandonne_la_candidature(): void
    {
        $test = RecruitmentTest::factory()->create();
        $question = RecruitmentQuestion::factory()->for($test)->create([
            'reponse_attendue' => ['classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0, 'explication' => ''],
        ]);
        $candidature = $this->recruitment->startCandidature($test, $this->identite());
        $this->recruitment->saveReponse($candidature, $question, ['classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0]);
        $this->recruitment->registerPageOpen($candidature);

        $second = $this->recruitment->registerPageOpen($candidature->fresh());

        $this->assertFalse($second);
        $candidature->refresh();
        $this->assertSame(RecruitmentCandidatureStatus::Abandonnee, $candidature->statut);
        $this->assertSame(3, $candidature->score);
        $this->assertNotNull($candidature->submitted_at);
    }

    public function test_registerpageopen_sur_candidature_deja_terminee_ne_reforfait_pas(): void
    {
        $test = RecruitmentTest::factory()->create();
        $candidature = $this->recruitment->startCandidature($test, $this->identite());
        $this->recruitment->submit($candidature);

        $result = $this->recruitment->registerPageOpen($candidature->fresh());

        $this->assertFalse($result);
        $this->assertSame(RecruitmentCandidatureStatus::Soumis, $candidature->fresh()->statut);
    }

    public function test_close_expired_cloture_et_note_les_candidatures_hors_delai(): void
    {
        $test = RecruitmentTest::factory()->create();
        $question = RecruitmentQuestion::factory()->for($test)->create([
            'reponse_attendue' => ['classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0, 'explication' => ''],
        ]);
        $candidature = $this->recruitment->startCandidature($test, $this->identite());
        $this->recruitment->saveReponse($candidature, $question, ['classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0]);
        $candidature->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

        $count = $this->recruitment->closeExpired();

        $this->assertSame(1, $count);
        $candidature->refresh();
        $this->assertSame(RecruitmentCandidatureStatus::Expire, $candidature->statut);
        $this->assertSame(3, $candidature->score);

        // Idempotent : un deuxième passage ne reclôture rien.
        $this->assertSame(0, $this->recruitment->closeExpired());
    }
}
