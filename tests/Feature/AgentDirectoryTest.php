<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Filiale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_admin_filiale_ne_voit_que_les_agents_de_sa_filiale(): void
    {
        $filialeA = Filiale::factory()->create();
        $filialeB = Filiale::factory()->create();

        $admin = User::factory()->filialeAdmin()->forFiliale($filialeA)->create();
        $agentA = User::factory()->agent()->forFiliale($filialeA)->create(['name' => 'Agent Alpha']);
        $agentB = User::factory()->agent()->forFiliale($filialeB)->create(['name' => 'Agent Beta']);

        $response = $this->actingAs($admin)->get(route('admin.annuaire.index'));

        $response->assertOk()->assertSee('Agent Alpha')->assertDontSee('Agent Beta');
    }

    public function test_un_super_admin_voit_toutes_les_filiales(): void
    {
        $filialeA = Filiale::factory()->create();
        $filialeB = Filiale::factory()->create();
        User::factory()->agent()->forFiliale($filialeA)->create(['name' => 'Agent Alpha']);
        User::factory()->agent()->forFiliale($filialeB)->create(['name' => 'Agent Beta']);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.annuaire.index'));

        $response->assertOk()->assertSee('Agent Alpha')->assertSee('Agent Beta');
    }

    public function test_un_compte_non_super_admin_sans_filiale_ne_voit_aucun_agent(): void
    {
        $filiale = Filiale::factory()->create();

        $orphelin = User::factory()->filialeAdmin()->create(['filiale_id' => null]);
        User::factory()->agent()->forFiliale($filiale)->create(['name' => 'Agent Alpha']);

        $response = $this->actingAs($orphelin)->get(route('admin.annuaire.index'));

        $response->assertOk()->assertDontSee('Agent Alpha');
    }
}
