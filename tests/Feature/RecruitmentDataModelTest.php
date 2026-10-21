<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RecruitmentCandidatureStatus;
use App\Models\Filiale;
use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentReponse;
use App\Models\RecruitmentTest;
use App\Support\FilialeScoping;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecruitmentDataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_test_de_recrutement_est_cree_avec_ses_questions(): void
    {
        $test = RecruitmentTest::factory()->create(['titre' => 'Fiscaliste Groupe']);
        RecruitmentQuestion::factory()->for($test)->count(3)->create();

        $this->assertSame(3, $test->questions()->count());
        $this->assertTrue($test->questions()->first()->test->is($test));
    }

    public function test_le_slug_est_unique(): void
    {
        RecruitmentTest::factory()->create(['slug' => 'fiscaliste-groupe']);

        $this->expectException(QueryException::class);
        RecruitmentTest::factory()->create(['slug' => 'fiscaliste-groupe']);
    }

    public function test_un_email_ne_peut_avoir_quune_seule_candidature_par_test(): void
    {
        $test = RecruitmentTest::factory()->create();
        RecruitmentCandidature::create($this->candidatureAttributes($test, 'a@exemple.ci'));

        $this->expectException(QueryException::class);
        RecruitmentCandidature::create($this->candidatureAttributes($test, 'a@exemple.ci'));
    }

    public function test_le_global_scope_filiale_cloisonne_les_tests_en_contexte_admin(): void
    {
        $filialeA = Filiale::factory()->create();
        $filialeB = Filiale::factory()->create();
        RecruitmentTest::factory()->create(['filiale_id' => $filialeA->id, 'titre' => 'Poste A']);
        RecruitmentTest::factory()->create(['filiale_id' => $filialeB->id, 'titre' => 'Poste B']);

        app(FilialeScoping::class)->scopeToFiliale($filialeA->id);
        $this->assertSame(['Poste A'], RecruitmentTest::pluck('titre')->all());

        app(FilialeScoping::class)->scopeToAll();
        $this->assertCount(2, RecruitmentTest::all());
    }

    public function test_hors_contexte_admin_le_scope_est_un_no_op(): void
    {
        Filiale::factory()->count(2)->create()->each(
            fn (Filiale $filiale) => RecruitmentTest::factory()->create(['filiale_id' => $filiale->id]),
        );

        // Aucun app(FilialeScoping::class)->scopeTo...() appelé : contexte inactif (cron/public/CLI).
        $this->assertCount(2, RecruitmentTest::all());
    }

    public function test_une_reponse_stocke_les_trois_champs_saisis(): void
    {
        $test = RecruitmentTest::factory()->create();
        $question = RecruitmentQuestion::factory()->for($test)->create();
        $candidature = RecruitmentCandidature::create($this->candidatureAttributes($test, 'b@exemple.ci'));

        $reponse = RecruitmentReponse::create([
            'recruitment_candidature_id' => $candidature->id,
            'recruitment_question_id' => $question->id,
            'classement_saisi' => 'Majeur',
            'ajustement_tva_saisi' => -168000,
            'retenue_saisie' => 0,
        ]);

        $this->assertTrue($reponse->question->is($question));
        $this->assertTrue($reponse->candidature->is($candidature));
        $this->assertSame(RecruitmentCandidatureStatus::EnCours, $candidature->fresh()->statut);
    }

    /** @return array<string, mixed> */
    private function candidatureAttributes(RecruitmentTest $test, string $email): array
    {
        return [
            'recruitment_test_id' => $test->id,
            'nom' => 'Doe',
            'prenom' => 'Jane',
            'email' => $email,
            'token' => bin2hex(random_bytes(20)),
            'started_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes(45),
            'statut' => RecruitmentCandidatureStatus::EnCours,
        ];
    }
}
