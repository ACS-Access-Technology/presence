<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_de_connexion_est_accessible(): void
    {
        $this->get('/connexion')->assertOk()->assertSee('Connexion');
    }

    public function test_connexion_reussie_redirige_vers_le_tableau_de_bord(): void
    {
        $user = User::factory()->create(['email' => 'orga@acsgroupe.ci']);

        $response = $this->post('/connexion', [
            'email' => 'orga@acsgroupe.ci',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);
    }

    public function test_mauvais_mot_de_passe_est_refuse(): void
    {
        User::factory()->create(['email' => 'orga@acsgroupe.ci']);

        $this->post('/connexion', ['email' => 'orga@acsgroupe.ci', 'password' => 'faux'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_compte_desactive_est_refuse(): void
    {
        User::factory()->inactive()->create(['email' => 'inactif@acsgroupe.ci']);

        $this->post('/connexion', ['email' => 'inactif@acsgroupe.ci', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_tableau_de_bord_exige_une_authentification(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }
}
