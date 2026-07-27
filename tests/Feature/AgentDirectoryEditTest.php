<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Filiale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentDirectoryEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_admin_filiale_edite_les_champs_d_annuaire_d_un_agent(): void
    {
        $filiale = Filiale::factory()->create();
        $admin = User::factory()->filialeAdmin()->forFiliale($filiale)->create();
        $agent = User::factory()->agent()->forFiliale($filiale)->create();

        $this->actingAs($admin)->patch(route('admin.annuaire.update', $agent), [
            'phone' => '+225 0708091011',
            'direction' => 'Direction IT',
            'service' => 'Sécurité',
            'position' => 'Consultant',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $agent->id,
            'direction' => 'Direction IT',
            'position' => 'Consultant',
        ]);
    }

    public function test_un_agent_ne_peut_pas_editer_l_annuaire(): void
    {
        $agent = User::factory()->agent()->create();
        $autre = User::factory()->agent()->create();

        $this->actingAs($agent)->patch(route('admin.annuaire.update', $autre), [
            'phone' => '+225 0000000000',
        ])->assertForbidden();
    }

    public function test_un_admin_filiale_ne_peut_pas_editer_un_agent_d_une_autre_filiale(): void
    {
        $filialeA = Filiale::factory()->create();
        $filialeB = Filiale::factory()->create();
        $admin = User::factory()->filialeAdmin()->forFiliale($filialeA)->create();
        $agentB = User::factory()->agent()->forFiliale($filialeB)->create(['direction' => 'Original']);

        $this->actingAs($admin)->patch(route('admin.annuaire.update', $agentB), [
            'direction' => 'Piraté',
        ])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $agentB->id, 'direction' => 'Original']);
    }

    public function test_un_admin_filiale_orphelin_sans_filiale_ne_peut_editer_personne(): void
    {
        // En production, `users.filiale_id` est en nullOnDelete() sur la FK
        // filiale (absente en SQLite, voir migration add_filiale_id_to_scoped_tables) :
        // un AdminFiliale orphelin après suppression de sa filiale se retrouve avec
        // filiale_id NULL. On simule directement cet état incohérent ici. Sans
        // garde explicite, la comparaison naïve `!==` le laisserait éditer
        // n'importe quel autre compte à filiale_id NULL, y compris un SuperAdmin.
        $admin = User::factory()->filialeAdmin()->create();
        $admin->forceFill(['filiale_id' => null])->save();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->patch(route('admin.annuaire.update', $superAdmin), [
            'phone' => '+225 0000000000',
        ])->assertForbidden();
    }
}
