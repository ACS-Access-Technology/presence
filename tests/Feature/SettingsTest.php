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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    // ---- Accès ----

    public function test_organisateur_na_pas_acces_aux_parametres(): void
    {
        $orga = User::factory()->create(['role' => UserRole::Organisateur]);
        $this->actingAs($orga)->get(route('admin.settings.index'))->assertForbidden();
    }

    public function test_admin_accede_aux_parametres(): void
    {
        $this->actingAs($this->admin())->get(route('admin.settings.index'))->assertOk()->assertSee('Paramètres');
    }

    // ---- Types ----

    public function test_admin_cree_un_type(): void
    {
        // Un SuperAdmin (admin() = SuperAdmin depuis le multi-filiale, contexte
        // « Toutes les filiales ») doit désigner la filiale cible du type
        // (cadrage Q-ME-10 : types uniquement par filiale, pas de repli holding).
        $this->actingAs($this->admin())->postJson(route('admin.settings.types.store'), [
            'name' => 'Séminaire', 'color' => '#123456', 'filiale_id' => Filiale::defaultId(),
        ])->assertStatus(201)->assertJsonPath('name', 'Séminaire');

        $this->assertDatabaseHas('event_types', ['name' => 'Séminaire', 'color' => '#123456']);
    }

    public function test_couleur_invalide_refusee(): void
    {
        $this->actingAs($this->admin())->postJson(route('admin.settings.types.store'), [
            'name' => 'X', 'color' => 'rouge', 'filiale_id' => Filiale::defaultId(),
        ])->assertStatus(422)->assertJsonValidationErrors('color');
    }

    public function test_type_utilise_non_supprimable(): void
    {
        $type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
        Event::create([
            'title' => 'E', 'event_type_id' => $type->id, 'starts_at' => Carbon::now(), 'ends_at' => Carbon::now()->addHour(),
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32), 'public_slug' => 'e',
        ]);

        $this->actingAs($this->admin())->deleteJson(route('admin.settings.types.destroy', $type))
            ->assertStatus(422);
        $this->assertDatabaseHas('event_types', ['id' => $type->id]);
    }

    // ---- Comptes ----

    public function test_admin_invite_un_compte_avec_mot_de_passe_temporaire(): void
    {
        $this->actingAs($this->admin())->postJson(route('admin.settings.accounts.store'), [
            'name' => 'Awa Diomandé', 'email' => 'awa@acsgroupe.ci', 'role' => 'organisateur',
        ])->assertStatus(201)
            ->assertJsonPath('account.email', 'awa@acsgroupe.ci')
            ->assertJsonStructure(['temp_password']);

        $this->assertDatabaseHas('users', ['email' => 'awa@acsgroupe.ci', 'role' => 'organisateur']);
    }

    public function test_admin_ne_peut_pas_se_retrograder(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->patchJson(route('admin.settings.accounts.update', $admin), [
            'role' => 'organisateur', 'is_active' => true,
        ])->assertStatus(422);
    }

    public function test_admin_ne_peut_pas_se_supprimer(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->deleteJson(route('admin.settings.accounts.destroy', $admin))->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_reset_password_renvoie_un_nouveau_mot_de_passe(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $oldHash = $target->password;

        $this->actingAs($admin)->postJson(route('admin.settings.accounts.reset', $target))
            ->assertOk()->assertJsonStructure(['temp_password']);

        $this->assertNotSame($oldHash, $target->refresh()->password);
    }

    // ---- État de connexion des comptes (never_connected / désactivé) ----

    public function test_settings_distingue_jamais_connecte_et_deja_connecte(): void
    {
        // La liste des comptes est hydratée côté client depuis un JSON embarqué
        // dans la page : on vérifie les deux branches de `never_connected`.
        $connecte = User::factory()->create([
            'name' => 'Koffi Connecté',
            'last_login_at' => Carbon::create(2026, 3, 15, 14, 30),
        ]);
        User::factory()->create(['name' => 'Awa Jamais', 'last_login_at' => null]);

        $html = $this->actingAs($this->admin())->get(route('admin.settings.index'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('"never_connected":true', $html);
        $this->assertStringContainsString('"never_connected":false', $html);
        // Le JSON embarqué échappe les non-ASCII (« é » → é) : on cible un
        // préfixe ASCII du libellé « Jamais connecté ».
        $this->assertStringContainsString('Jamais connect', $html);
        // Le compte déjà connecté expose sa date formatée (locale FR).
        $this->assertSame('15 mars 2026 · 14:30', $connecte->last_login_at->translatedFormat('j M Y · H:i'));
        $this->assertStringContainsString('15 mars 2026', $html);
        $this->assertStringContainsString('14:30', $html);
    }

    public function test_settings_expose_letat_desactive_dun_compte(): void
    {
        // « Désactivé » (is_active=false) est un état distinct de « jamais connecté ».
        User::factory()->inactive()->create(['name' => 'Compte Suspendu', 'last_login_at' => Carbon::now()]);

        $this->actingAs($this->admin())->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('"is_active":false', false);
    }

    // ---- Branding ----

    public function test_admin_enregistre_le_branding(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin())->post(route('admin.settings.branding'), [
            'org_name' => 'ACS Test', 'timezone' => 'Africa/Abidjan', 'date_format' => 'd/m/Y',
        ])->assertRedirect(route('admin.settings.index'));

        $this->assertSame('ACS Test', Setting::get('org_name'));
        $this->assertSame('d/m/Y', Setting::get('date_format'));
    }

    /**
     * Toggle « hériter du branding de la holding » (brand_inherit, Q-ME-8).
     * On active l'héritage : le contrôleur court-circuite et n'écrit PAS le
     * branding propre soumis. La branche « désactivé » est déjà couverte par
     * MultiFilialeAdminTest.
     */
    public function test_admin_filiale_active_lheritage_du_branding_holding(): void
    {
        $filiale = Filiale::factory()->create(['name' => 'ACS Energie']);
        $adminFiliale = User::factory()->filialeAdmin()->forFiliale($filiale)->create();

        // Point de départ : branding propre (héritage désactivé).
        Setting::set('brand_inherit', '0', $filiale->id);
        Setting::set('org_name', 'ACS Energie personnalisé', $filiale->id);
        $this->assertFalse(Setting::inheritsBranding($filiale->id));

        $this->actingAs($adminFiliale)->post(route('admin.settings.branding'), [
            'org_name' => 'Ne doit pas être enregistré', 'brand_inherit' => '1',
        ])->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('status');

        $this->assertTrue(Setting::inheritsBranding($filiale->id));
        $this->assertSame('1', Setting::get('brand_inherit', $filiale->id));
        // Court-circuit : l'org_name propre existant n'est pas écrasé.
        $this->assertSame('ACS Energie personnalisé', Setting::get('org_name', $filiale->id));
    }

    public function test_une_filiale_neuve_herite_par_defaut_du_branding_holding(): void
    {
        // Absence de la clé brand_inherit ⇒ héritage par défaut (une filiale neuve
        // reprend le branding holding).
        $filiale = Filiale::factory()->create(['name' => 'ACS Nouvelle']);

        $this->assertTrue(Setting::inheritsBranding($filiale->id));
        // La holding, elle, n'hérite de personne.
        $this->assertFalse(Setting::inheritsBranding(Filiale::defaultId()));
    }

    // ---- Réassignation de filiale : validation adversariale ----

    public function test_reassign_refuse_une_filiale_inexistante(): void
    {
        $orga = User::factory()->create();

        $this->actingAs($this->admin())->postJson(route('admin.settings.accounts.reassign', $orga), [
            'filiale_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors('filiale_id');
    }

    public function test_reassign_refuse_une_filiale_desactivee(): void
    {
        $orga = User::factory()->create();
        $inactive = Filiale::factory()->inactive()->create(['name' => 'ACS Fermée']);

        $this->actingAs($this->admin())->postJson(route('admin.settings.accounts.reassign', $orga), [
            'filiale_id' => $inactive->id,
        ])->assertStatus(422)->assertJsonValidationErrors('filiale_id');

        $this->assertNotSame($inactive->id, $orga->refresh()->filiale_id);
    }

    public function test_reassign_exige_une_filiale(): void
    {
        $orga = User::factory()->create();

        $this->actingAs($this->admin())->postJson(route('admin.settings.accounts.reassign', $orga), [])
            ->assertStatus(422)->assertJsonValidationErrors('filiale_id');
    }
}
