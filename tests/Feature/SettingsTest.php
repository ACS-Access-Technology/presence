<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventType;
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
        $this->actingAs($this->admin())->postJson(route('admin.settings.types.store'), [
            'name' => 'Séminaire', 'color' => '#123456',
        ])->assertStatus(201)->assertJsonPath('name', 'Séminaire');

        $this->assertDatabaseHas('event_types', ['name' => 'Séminaire', 'color' => '#123456']);
    }

    public function test_couleur_invalide_refusee(): void
    {
        $this->actingAs($this->admin())->postJson(route('admin.settings.types.store'), [
            'name' => 'X', 'color' => 'rouge',
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
}
