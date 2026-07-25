<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Autorisation multi-filiales (Lots C/D/F). Prouve notamment la FERMETURE des
 * deux vulnérabilités HIGH de la revue de sécurité du 2026-07-25 :
 *   (1) escalade de privilège : un AdminFiliale s'auto-promouvant super_admin ;
 *   (2) IDOR : un AdminFiliale éditant/supprimant un compte ou un type d'une
 *       AUTRE filiale via un route-model binding non scopé.
 */
class MultiFilialeAdminTest extends TestCase
{
    use RefreshDatabase;

    private Filiale $filialeA;

    private Filiale $filialeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filialeA = Filiale::factory()->create(['name' => 'ACS Immobilier']);
        $this->filialeB = Filiale::factory()->create(['name' => 'ACS Energie']);
    }

    private function type(Filiale $filiale, string $name): EventType
    {
        return EventType::create(['filiale_id' => $filiale->id, 'name' => $name, 'color' => '#7c3aed', 'position' => 0]);
    }

    private function adminOf(Filiale $filiale): User
    {
        return User::factory()->filialeAdmin()->forFiliale($filiale)->create();
    }

    // ======================================================================
    // VULNÉRABILITÉ 1 — Escalade de privilège (rôle super_admin)
    // ======================================================================

    public function test_vuln_escalade_admin_filiale_ne_peut_pas_se_promouvoir_super_admin(): void
    {
        $admin = $this->adminOf($this->filialeA);

        // Double barrière : la policy refuse qu'un AdminFiliale se gère lui-même
        // (403, il n'est pas un Organisateur de sa filiale), et même s'il passait,
        // le rôle super_admin n'est pas dans les rôles assignables (422). Dans les
        // deux cas, aucune escalade possible.
        $this->actingAs($admin)->patchJson(route('admin.settings.accounts.update', $admin), [
            'role' => 'super_admin', 'is_active' => true,
        ])->assertForbidden();

        $this->assertSame(UserRole::AdminFiliale, $admin->refresh()->role);
    }

    public function test_vuln_escalade_admin_filiale_ne_peut_pas_promouvoir_un_tiers_super_admin(): void
    {
        $admin = $this->adminOf($this->filialeA);
        $orga = User::factory()->forFiliale($this->filialeA)->create();

        $this->actingAs($admin)->patchJson(route('admin.settings.accounts.update', $orga), [
            'role' => 'super_admin', 'is_active' => true,
        ])->assertStatus(422);

        $this->assertSame(UserRole::Organisateur, $orga->refresh()->role);
    }

    public function test_vuln_escalade_admin_filiale_ne_peut_pas_creer_un_super_admin_ni_un_admin_filiale(): void
    {
        $admin = $this->adminOf($this->filialeA);

        foreach (['super_admin', 'admin_filiale'] as $role) {
            $this->actingAs($admin)->postJson(route('admin.settings.accounts.store'), [
                'name' => 'X', 'email' => "x-{$role}@acs.ci", 'role' => $role,
            ])->assertStatus(422);
        }

        $this->assertDatabaseMissing('users', ['email' => 'x-super_admin@acs.ci']);
        $this->assertDatabaseMissing('users', ['email' => 'x-admin_filiale@acs.ci']);
    }

    // ======================================================================
    // VULNÉRABILITÉ 2 — IDOR (accès croisé à une ressource d'une autre filiale)
    // ======================================================================

    public function test_vuln_idor_admin_filiale_ne_peut_pas_modifier_un_compte_dune_autre_filiale(): void
    {
        $adminA = $this->adminOf($this->filialeA);
        $orgaB = User::factory()->forFiliale($this->filialeB)->create();

        $this->actingAs($adminA)->patchJson(route('admin.settings.accounts.update', $orgaB), [
            'role' => 'organisateur', 'is_active' => false,
        ])->assertForbidden();

        $this->assertTrue($orgaB->refresh()->is_active);
    }

    public function test_vuln_idor_admin_filiale_ne_peut_ni_supprimer_ni_reset_un_compte_dune_autre_filiale(): void
    {
        $adminA = $this->adminOf($this->filialeA);
        $orgaB = User::factory()->forFiliale($this->filialeB)->create();
        $oldHash = $orgaB->password;

        $this->actingAs($adminA)->deleteJson(route('admin.settings.accounts.destroy', $orgaB))->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $orgaB->id]);

        $this->actingAs($adminA)->postJson(route('admin.settings.accounts.reset', $orgaB))->assertForbidden();
        $this->assertSame($oldHash, $orgaB->refresh()->password);
    }

    public function test_vuln_idor_admin_filiale_ne_peut_pas_modifier_un_type_dune_autre_filiale(): void
    {
        $adminA = $this->adminOf($this->filialeA);
        $typeB = $this->type($this->filialeB, 'Type B');

        // Global scope de filiale → le type d'une autre filiale est introuvable (404).
        $this->actingAs($adminA)->patchJson(route('admin.settings.types.update', $typeB), [
            'name' => 'Piraté', 'color' => '#000000',
        ])->assertNotFound();

        $this->actingAs($adminA)->deleteJson(route('admin.settings.types.destroy', $typeB))->assertNotFound();
        $this->assertDatabaseHas('event_types', ['id' => $typeB->id, 'name' => 'Type B']);
    }

    public function test_admin_filiale_ne_peut_pas_changer_la_filiale_dun_compte_sans_etre_super_admin(): void
    {
        $adminA = $this->adminOf($this->filialeA);
        $orgaA = User::factory()->forFiliale($this->filialeA)->create();

        // La route reassign est réservée au SuperAdmin (role:super_admin) → 403.
        $this->actingAs($adminA)->postJson(route('admin.settings.accounts.reassign', $orgaA), [
            'filiale_id' => $this->filialeB->id,
        ])->assertForbidden();

        $this->assertSame($this->filialeA->id, $orgaA->refresh()->filiale_id);
    }

    // ======================================================================
    // Comportements nominaux — comptes scopés
    // ======================================================================

    public function test_admin_filiale_cree_un_organisateur_dans_sa_propre_filiale(): void
    {
        $adminA = $this->adminOf($this->filialeA);

        $this->actingAs($adminA)->postJson(route('admin.settings.accounts.store'), [
            'name' => 'Sarah', 'email' => 'sarah@acs-immo.ci', 'role' => 'organisateur',
            'filiale_id' => $this->filialeB->id, // tentative d'injection : doit être ignorée
        ])->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'sarah@acs-immo.ci', 'role' => 'organisateur', 'filiale_id' => $this->filialeA->id,
        ]);
    }

    /**
     * Régression : `postJson()` sérialise `filiale_id` en JSON natif (reste un
     * int), ce qui masquait un `TypeError` (strict_types) déclenché en
     * production par le vrai formulaire (FormData → tout est une chaîne). Ce
     * test simule la vraie requête HTTP form-encodée pour l'attraper.
     */
    public function test_super_admin_cree_un_compte_avec_filiale_id_en_chaine_form_encodee(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post(route('admin.settings.accounts.store'), [
            'name' => 'Yao Kouadio', 'email' => 'yao.kouadio@acs-digital.ci', 'role' => 'admin_filiale',
            'filiale_id' => (string) $this->filialeB->id,
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'yao.kouadio@acs-digital.ci', 'role' => 'admin_filiale', 'filiale_id' => $this->filialeB->id,
        ]);
    }

    public function test_admin_filiale_gere_un_organisateur_de_sa_filiale(): void
    {
        $adminA = $this->adminOf($this->filialeA);
        $orgaA = User::factory()->forFiliale($this->filialeA)->create();

        $this->actingAs($adminA)->patchJson(route('admin.settings.accounts.update', $orgaA), [
            'role' => 'organisateur', 'is_active' => false,
        ])->assertOk();

        $this->assertFalse($orgaA->refresh()->is_active);
    }

    public function test_settings_admin_filiale_ne_voit_que_les_comptes_de_sa_filiale(): void
    {
        $adminA = $this->adminOf($this->filialeA);
        User::factory()->forFiliale($this->filialeA)->create(['name' => 'Compte Interne A']);
        User::factory()->forFiliale($this->filialeB)->create(['name' => 'Compte Etranger B']);

        $this->actingAs($adminA)->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Compte Interne A')
            ->assertDontSee('Compte Etranger B');
    }

    // ======================================================================
    // Réassignation (SuperAdmin uniquement)
    // ======================================================================

    public function test_super_admin_reassigne_un_compte_entre_filiales(): void
    {
        $super = User::factory()->superAdmin()->create();
        $orgaA = User::factory()->forFiliale($this->filialeA)->create();

        $this->actingAs($super)->postJson(route('admin.settings.accounts.reassign', $orgaA), [
            'filiale_id' => $this->filialeB->id,
        ])->assertOk();

        $this->assertSame($this->filialeB->id, $orgaA->refresh()->filiale_id);
    }

    public function test_super_admin_ne_reassigne_pas_un_super_admin(): void
    {
        $super = User::factory()->superAdmin()->create();
        $other = User::factory()->superAdmin()->create();

        $this->actingAs($super)->postJson(route('admin.settings.accounts.reassign', $other), [
            'filiale_id' => $this->filialeB->id,
        ])->assertStatus(422);

        $this->assertNull($other->refresh()->filiale_id);
    }

    // ======================================================================
    // Types d'événement — unicité par filiale
    // ======================================================================

    public function test_deux_filiales_peuvent_avoir_un_type_du_meme_nom(): void
    {
        $this->type($this->filialeA, 'Atelier');
        $this->type($this->filialeB, 'Atelier'); // aucune collision d'unicité

        $this->assertSame(2, EventType::whereIn('filiale_id', [$this->filialeA->id, $this->filialeB->id])
            ->where('name', 'Atelier')->count());
    }

    public function test_nom_de_type_unique_dans_une_meme_filiale(): void
    {
        $adminA = $this->adminOf($this->filialeA);
        $this->type($this->filialeA, 'Atelier');

        $this->actingAs($adminA)->postJson(route('admin.settings.types.store'), [
            'name' => 'Atelier', 'color' => '#123456',
        ])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    // ======================================================================
    // Filiales — CRUD SuperAdmin
    // ======================================================================

    public function test_admin_filiale_na_pas_acces_a_la_gestion_des_filiales(): void
    {
        $adminA = $this->adminOf($this->filialeA);
        $this->actingAs($adminA)->get(route('admin.filiales.index'))->assertForbidden();
        $this->actingAs($adminA)->postJson(route('admin.filiales.store'), ['name' => 'Pirate'])->assertForbidden();
    }

    public function test_super_admin_cree_renomme_et_bascule_une_filiale(): void
    {
        $super = User::factory()->superAdmin()->create();

        $res = $this->actingAs($super)->postJson(route('admin.filiales.store'), ['name' => 'ACS Santé'])
            ->assertStatus(201)->json();
        $this->assertDatabaseHas('filiales', ['name' => 'ACS Santé', 'is_active' => true]);

        $id = $res['id'];
        $this->actingAs($super)->patchJson(route('admin.filiales.update', $id), ['name' => 'ACS Santé & Bien-être'])->assertOk();
        $this->assertDatabaseHas('filiales', ['id' => $id, 'name' => 'ACS Santé & Bien-être']);

        $this->actingAs($super)->patchJson(route('admin.filiales.toggle', $id))->assertOk();
        $this->assertDatabaseHas('filiales', ['id' => $id, 'is_active' => false]);
    }

    public function test_la_filiale_par_defaut_ne_peut_pas_etre_desactivee(): void
    {
        $super = User::factory()->superAdmin()->create();
        $default = Filiale::where('slug', Filiale::DEFAULT_SLUG)->firstOrFail();

        $this->actingAs($super)->patchJson(route('admin.filiales.toggle', $default))->assertStatus(422);
        $this->assertTrue($default->refresh()->is_active);
    }

    public function test_une_filiale_desactivee_bloque_la_connexion_de_ses_comptes(): void
    {
        $orga = User::factory()->forFiliale($this->filialeA)->create(['email' => 'bloque@acs.ci']);
        $this->filialeA->update(['is_active' => false]);

        $this->post('/connexion', ['email' => 'bloque@acs.ci', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // ======================================================================
    // Sélecteur de contexte filiale (SuperAdmin)
    // ======================================================================

    private function event(Filiale $filiale, string $title): Event
    {
        return Event::create([
            'filiale_id' => $filiale->id, 'title' => $title,
            'event_type_id' => $this->type($filiale, $title.' type')->id,
            'starts_at' => Carbon::now()->subHour(), 'ends_at' => Carbon::now()->addHour(),
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32),
            'public_slug' => Str::slug($title).'-'.Str::random(5),
        ]);
    }

    public function test_super_admin_selectionne_une_filiale_et_les_ecrans_sont_scopes(): void
    {
        $this->event($this->filialeA, 'Evenement Alpha');
        $this->event($this->filialeB, 'Evenement Beta');
        $super = User::factory()->superAdmin()->create();

        // Contexte « Toutes » par défaut : les deux sont visibles.
        $this->actingAs($super)->get(route('admin.events.index'))
            ->assertSee('Evenement Alpha')->assertSee('Evenement Beta');

        // Sélection de la filiale A → seule A est visible.
        $this->actingAs($super)->post(route('admin.filiale-context.update'), ['filiale_id' => $this->filialeA->id])
            ->assertRedirect();
        $this->actingAs($super)->get(route('admin.events.index'))
            ->assertSee('Evenement Alpha')->assertDontSee('Evenement Beta');

        // Retour à « Toutes » → de nouveau les deux.
        $this->actingAs($super)->post(route('admin.filiale-context.update'), ['filiale_id' => null])
            ->assertRedirect();
        $this->actingAs($super)->get(route('admin.events.index'))
            ->assertSee('Evenement Alpha')->assertSee('Evenement Beta');
    }

    /** Raccourci « Voir » depuis la gestion des filiales : bascule le contexte ET redirige sur le dashboard. */
    public function test_super_admin_bascule_le_contexte_et_atterrit_sur_le_dashboard_depuis_filiales(): void
    {
        $this->event($this->filialeA, 'Evenement Alpha');
        $this->event($this->filialeB, 'Evenement Beta');
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->post(route('admin.filiale-context.update'), [
            'filiale_id' => $this->filialeA->id, 'redirect_to' => 'dashboard',
        ])->assertRedirect(route('admin.dashboard'));

        $this->actingAs($super)->get(route('admin.events.index'))
            ->assertSee('Evenement Alpha')->assertDontSee('Evenement Beta');
    }

    // ======================================================================
    // Branding par filiale (Lot F) — repli holding, réglages globaux non surchargeables
    // ======================================================================

    public function test_admin_filiale_enregistre_son_branding_sans_toucher_aux_reglages_globaux(): void
    {
        $adminA = $this->adminOf($this->filialeA);

        $this->actingAs($adminA)->post(route('admin.settings.branding'), [
            'org_name' => 'ACS Immobilier', 'brand_inherit' => '0', 'accent_color' => '#123456',
            // timezone / date_format ne sont PAS acceptés pour une filiale.
        ])->assertRedirect(route('admin.settings.index'));

        $this->assertSame('ACS Immobilier', Setting::get('org_name', $this->filialeA->id));
        // Le fuseau reste global (aucune ligne filiale) : lu depuis la holding.
        $this->assertSame(config('app.timezone'), Setting::branding($this->filialeA->id)['timezone']);
    }

    public function test_le_branding_dune_filiale_herite_de_la_holding_par_defaut(): void
    {
        Setting::set('org_name', 'ACS Groupe Holding'); // holding
        // La filiale n'a pas défini de branding propre → héritage.
        $this->assertSame('ACS Groupe Holding', Setting::branding($this->filialeA->id)['org_name']);
    }
}
