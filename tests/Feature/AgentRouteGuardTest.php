<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Moindre privilège du rôle Agent : aucune surface opérationnelle (événements,
 * participants, portfolio, statistiques) ne lui est ouverte, même si le
 * provisioning d'un compte Agent n'est pas encore exposé dans l'application.
 */
class AgentRouteGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function surfacesOperationnelles(): array
    {
        return [
            'événements' => ['admin.events.index'],
            'création événement' => ['admin.events.create'],
            'participants' => ['admin.participants.index'],
            'portfolio' => ['admin.portfolio'],
            'statistiques' => ['admin.statistics'],
        ];
    }

    #[DataProvider('surfacesOperationnelles')]
    public function test_un_agent_n_accede_pas_aux_surfaces_operationnelles(string $route): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)->get(route($route))->assertForbidden();
    }

    public function test_l_accueil_d_un_agent_redirige_vers_l_annuaire(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.annuaire.index'));
        $this->actingAs($agent)->get(route('admin.annuaire.index'))->assertOk();
    }

    public function test_un_organisateur_garde_l_acces_aux_evenements(): void
    {
        $organisateur = User::factory()->create();

        $this->actingAs($organisateur)->get(route('admin.events.index'))->assertOk();
    }
}
