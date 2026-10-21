<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RecruitmentCandidatureStatus;
use App\Models\Filiale;
use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentReponse;
use App\Models\RecruitmentTest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminRecruitmentCandidatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_liste_des_candidatures_saffiche_pour_le_bon_poste(): void
    {
        $filiale = Filiale::factory()->create();
        $admin = User::factory()->filialeAdmin()->forFiliale($filiale)->create();
        $test = RecruitmentTest::factory()->create(['filiale_id' => $filiale->id]);
        RecruitmentCandidature::create([
            'recruitment_test_id' => $test->id, 'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'x@exemple.ci',
            'token' => bin2hex(random_bytes(20)), 'started_at' => Carbon::now(), 'expires_at' => Carbon::now()->addMinutes(45),
            'statut' => RecruitmentCandidatureStatus::Soumis, 'score' => 45, 'submitted_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.recrutement.candidatures.index', $test->slug));

        $response->assertOk()->assertSee('Doe')->assertSee('45');
    }

    public function test_un_adminfiliale_dune_autre_filiale_ne_voit_pas_le_poste(): void
    {
        $filialeA = Filiale::factory()->create();
        $filialeB = Filiale::factory()->create();
        $adminB = User::factory()->filialeAdmin()->forFiliale($filialeB)->create();
        $test = RecruitmentTest::factory()->create(['filiale_id' => $filialeA->id]);

        $this->actingAs($adminB)->get(route('admin.recrutement.candidatures.index', $test->slug))->assertNotFound();
    }

    public function test_le_detail_affiche_la_reponse_du_candidat_face_a_lattendu(): void
    {
        $filiale = Filiale::factory()->create();
        $admin = User::factory()->filialeAdmin()->forFiliale($filiale)->create();
        $test = RecruitmentTest::factory()->create(['filiale_id' => $filiale->id]);
        $question = RecruitmentQuestion::factory()->for($test)->create([
            'contexte' => ['id' => 'T001', 'entite' => 'X', 'date' => '2026-06-03', 'piece' => 'FV-1', 'tiers' => 'Client', 'nature' => 'Vente', 'montant_ht' => 100, 'tva_comptabilisee' => 18, 'retenue_operee' => 0, 'informations' => 'RAS'],
            'reponse_attendue' => ['classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0, 'explication' => 'OK'],
        ]);
        $candidature = RecruitmentCandidature::create([
            'recruitment_test_id' => $test->id, 'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'x@exemple.ci',
            'token' => bin2hex(random_bytes(20)), 'started_at' => Carbon::now(), 'expires_at' => Carbon::now()->addMinutes(45),
            'statut' => RecruitmentCandidatureStatus::Soumis, 'score' => 3, 'submitted_at' => Carbon::now(),
        ]);
        RecruitmentReponse::create([
            'recruitment_candidature_id' => $candidature->id, 'recruitment_question_id' => $question->id,
            'classement_saisi' => 'Conforme', 'ajustement_tva_saisi' => 0, 'retenue_saisie' => 0,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.recrutement.candidatures.show', [$test->slug, $candidature->id]));

        $response->assertOk()->assertSee('T001')->assertSee('Conforme');
    }
}
