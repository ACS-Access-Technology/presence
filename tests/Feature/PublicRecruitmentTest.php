<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RecruitmentCandidatureStatus;
use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentTest;
use App\Services\RecruitmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PublicRecruitmentTest extends TestCase
{
    use RefreshDatabase;

    private function test(): RecruitmentTest
    {
        return RecruitmentTest::factory()->create(['slug' => 'fiscaliste-groupe', 'duree_minutes' => 45]);
    }

    public function test_la_page_du_poste_saffiche(): void
    {
        $test = $this->test();

        $this->get(route('public.recruitment.show', $test->slug))
            ->assertOk()
            ->assertSee($test->titre);
    }

    public function test_un_poste_inactif_renvoie_404(): void
    {
        $test = RecruitmentTest::factory()->create(['slug' => 'inactif', 'actif' => false]);

        $this->get(route('public.recruitment.show', $test->slug))->assertNotFound();
    }

    public function test_demarrer_le_test_redirige_vers_la_page_de_test(): void
    {
        $test = $this->test();

        $response = $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'candidat@exemple.ci',
        ]);

        $candidature = RecruitmentCandidature::where('email', 'candidat@exemple.ci')->firstOrFail();
        $response->assertRedirect(route('public.recruitment.test', ['test' => $test->slug, 'token' => $candidature->token]));
    }

    public function test_un_email_deja_utilise_est_rejete(): void
    {
        $test = $this->test();
        $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'deja@exemple.ci',
        ]);

        // Autre navigateur (session vierge) : aucun jeton récupérable.
        $this->flushSession();
        $response = $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Autre', 'prenom' => 'Personne', 'email' => 'DEJA@exemple.ci ',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertSame(1, RecruitmentCandidature::where('email', 'deja@exemple.ci')->count());
    }

    public function test_un_double_envoi_du_formulaire_renvoie_vers_la_meme_candidature(): void
    {
        $test = $this->test();
        $identite = ['nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'double@exemple.ci'];

        $this->post(route('public.recruitment.store', $test->slug), $identite);
        $response = $this->post(route('public.recruitment.store', $test->slug), $identite);

        $candidature = RecruitmentCandidature::where('email', 'double@exemple.ci')->sole();
        $response->assertRedirect(route('public.recruitment.test', ['test' => $test->slug, 'token' => $candidature->token]));
    }

    public function test_la_session_ne_rend_pas_le_jeton_pour_un_autre_email(): void
    {
        $test = $this->test();
        $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'premier@exemple.ci',
        ]);
        // Second candidat sur le même poste/navigateur, email déjà pris par ailleurs.
        app(RecruitmentService::class)->startCandidature($test, [
            'nom' => 'Autre', 'prenom' => 'Poste', 'email' => 'second@exemple.ci', 'telephone' => null,
        ]);

        $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Roe', 'prenom' => 'Jim', 'email' => 'second@exemple.ci',
        ])->assertSessionHasErrors('email');
    }

    public function test_la_page_de_test_affiche_les_questions_et_accepte_lautosave(): void
    {
        $test = $this->test();
        $question = RecruitmentQuestion::factory()->for($test)->create();
        $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'autosave@exemple.ci',
        ]);
        $candidature = RecruitmentCandidature::where('email', 'autosave@exemple.ci')->firstOrFail();

        $this->get(route('public.recruitment.test', ['test' => $test->slug, 'token' => $candidature->token]))
            ->assertOk()
            ->assertSee($question->contexte['id']);

        $answer = $this->patchJson(
            route('public.recruitment.answer', ['test' => $test->slug, 'token' => $candidature->token, 'question' => $question->id]),
            ['classement' => 'Majeur', 'ajustement_tva' => -168000, 'retenue_attendue' => 0],
        );
        $answer->assertOk()->assertJson(['saved' => true]);
        $this->assertDatabaseHas('recruitment_reponses', [
            'recruitment_candidature_id' => $candidature->id,
            'recruitment_question_id' => $question->id,
            'classement_saisi' => 'Majeur',
        ]);
    }

    public function test_recharger_la_page_de_test_abandonne_la_candidature(): void
    {
        $test = $this->test();
        RecruitmentQuestion::factory()->for($test)->create();
        $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'reload@exemple.ci',
        ]);
        $candidature = RecruitmentCandidature::where('email', 'reload@exemple.ci')->firstOrFail();

        $testRoute = route('public.recruitment.test', ['test' => $test->slug, 'token' => $candidature->token]);
        $this->get($testRoute)->assertOk();

        // Deuxième chargement (rafraîchissement / réouverture du lien) : abandon.
        $second = $this->get($testRoute);

        $second->assertOk()->assertSeeText("n'est plus disponible");
        $this->assertSame(RecruitmentCandidatureStatus::Abandonnee, $candidature->fresh()->statut);
    }

    public function test_soumettre_affiche_la_page_de_confirmation_sans_score(): void
    {
        $test = $this->test();
        $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'soumis@exemple.ci',
        ]);
        $candidature = RecruitmentCandidature::where('email', 'soumis@exemple.ci')->firstOrFail();

        $response = $this->post(route('public.recruitment.submit', ['test' => $test->slug, 'token' => $candidature->token]));

        $response->assertOk();
        $response->assertViewIs('public.recruitment.merci');
        $response->assertViewMissing('score');
        $response->assertViewMissing('candidature');
    }

    public function test_repondre_apres_expiration_renvoie_410(): void
    {
        $test = $this->test();
        $question = RecruitmentQuestion::factory()->for($test)->create();
        $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'expire@exemple.ci',
        ]);
        $candidature = RecruitmentCandidature::where('email', 'expire@exemple.ci')->firstOrFail();
        $candidature->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

        $response = $this->patchJson(
            route('public.recruitment.answer', ['test' => $test->slug, 'token' => $candidature->token, 'question' => $question->id]),
            ['classement' => 'Majeur'],
        );

        $response->assertStatus(410);
    }
}
