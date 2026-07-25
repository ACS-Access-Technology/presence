<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureActiveSession;
use App\Models\Filiale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Révocation de session « à chaud » (anomalie ÉLEVÉ). `LoginRequest` ne vérifie
 * `is_active` (compte + filiale) qu'À LA CONNEXION ; une session déjà ouverte
 * survivait à une désactivation faite entre-temps par un SuperAdmin/AdminFiliale.
 *
 * Le middleware {@see EnsureActiveSession}, posé sur le
 * groupe admin, reverifie l'état à CHAQUE requête authentifiée et éjecte
 * immédiatement (déconnexion + invalidation de session) tout compte ou filiale
 * désactivé. Ces tests prouvent la fermeture de la faille.
 */
class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    private Filiale $filialeA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filialeA = Filiale::factory()->create(['name' => 'ACS Immobilier']);
    }

    // (a) Compte désactivé pendant une session active → éjecté à la requête suivante.
    public function test_compte_desactive_pendant_la_session_perd_l_acces_a_la_requete_suivante(): void
    {
        $user = User::factory()->forFiliale($this->filialeA)->create();

        // Session nominale : l'accès fonctionne.
        $this->actingAs($user)->get(route('admin.events.index'))->assertOk();

        // Un admin désactive le compte pendant que la session est ouverte.
        $user->is_active = false;
        $user->save();

        $this->actingAs($user)->get(route('admin.events.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // (a bis) Le message affiché est explicite pour le compte désactivé.
    public function test_compte_desactive_affiche_un_message_clair(): void
    {
        $user = User::factory()->forFiliale($this->filialeA)->create(['is_active' => false]);

        $this->actingAs($user)->get(route('admin.events.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Votre compte a été désactivé.']);
    }

    // (b) Filiale désactivée coupe l'accès de son AdminFiliale ET de son Organisateur.
    public function test_filiale_desactivee_coupe_l_acces_de_l_admin_filiale(): void
    {
        $admin = User::factory()->filialeAdmin()->forFiliale($this->filialeA)->create();
        $this->actingAs($admin)->get(route('admin.events.index'))->assertOk();

        $this->filialeA->update(['is_active' => false]);

        // Instance fraîche : en requête réelle, le SessionGuard recharge le
        // compte (et sa filiale) depuis la DB à chaque requête. `fresh()` évite
        // la relation `filiale` mise en cache sur l'instance du 1er appel.
        $this->actingAs($admin->fresh())->get(route('admin.events.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Votre filiale a été désactivée.']);
        $this->assertGuest();
    }

    public function test_filiale_desactivee_coupe_l_acces_de_l_organisateur(): void
    {
        $orga = User::factory()->forFiliale($this->filialeA)->create();
        $this->actingAs($orga)->get(route('admin.events.index'))->assertOk();

        $this->filialeA->update(['is_active' => false]);

        $this->actingAs($orga->fresh())->get(route('admin.events.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // (b + c) La désactivation d'une filiale n'affecte JAMAIS le SuperAdmin (sans filiale).
    public function test_super_admin_n_est_pas_affecte_par_une_filiale_desactivee(): void
    {
        $this->filialeA->update(['is_active' => false]);
        $super = User::factory()->superAdmin()->create();

        // Le SuperAdmin n'a pas de filiale (filiale_id NULL) : la vérification
        // filiale ne le concerne pas, il garde l'accès.
        $this->actingAs($super)->get(route('admin.events.index'))->assertOk();
        $this->assertAuthenticatedAs($super);
    }

    // (c) Un SuperAdmin actif garde l'accès en toutes circonstances.
    public function test_super_admin_actif_conserve_l_acces(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)->get(route('admin.events.index'))->assertOk();
    }

    // Requête JSON/AJAX : réponse 401 propre avec cible de redirection, pas une
    // page HTML de connexion qui casserait un fetch en cours côté client.
    public function test_compte_desactive_recoit_une_reponse_json_401_pour_une_requete_ajax(): void
    {
        $user = User::factory()->forFiliale($this->filialeA)->create(['is_active' => false]);

        $this->actingAs($user)->getJson(route('admin.events.index'))
            ->assertStatus(401)
            ->assertJson([
                'message' => 'Votre compte a été désactivé.',
                'redirect' => route('login'),
            ]);
        $this->assertGuest();
    }

    // Non-régression : un compte actif d'une filiale active n'est jamais éjecté.
    public function test_compte_actif_filiale_active_conserve_l_acces(): void
    {
        $user = User::factory()->forFiliale($this->filialeA)->create();
        $this->actingAs($user)->get(route('admin.events.index'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}
