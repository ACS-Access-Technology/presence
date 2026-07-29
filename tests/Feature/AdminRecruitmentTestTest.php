<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Filiale;
use App\Models\RecruitmentTest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRecruitmentTestTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_adminfiliale_ne_voit_que_les_postes_de_sa_filiale(): void
    {
        $filialeA = Filiale::factory()->create();
        $filialeB = Filiale::factory()->create();
        RecruitmentTest::factory()->create(['filiale_id' => $filialeA->id, 'titre' => 'Poste A']);
        RecruitmentTest::factory()->create(['filiale_id' => $filialeB->id, 'titre' => 'Poste B']);
        $admin = User::factory()->filialeAdmin()->forFiliale($filialeA)->create();

        $response = $this->actingAs($admin)->get(route('admin.recrutement.index'));

        $response->assertOk()->assertSee('Poste A')->assertDontSee('Poste B');
    }

    public function test_un_agent_ne_peut_pas_acceder_a_la_liste(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)->get(route('admin.recrutement.index'))->assertForbidden();
    }

    public function test_toggle_actif_inverse_le_statut(): void
    {
        $filiale = Filiale::factory()->create();
        $admin = User::factory()->filialeAdmin()->forFiliale($filiale)->create();
        $test = RecruitmentTest::factory()->create(['filiale_id' => $filiale->id, 'actif' => true]);

        $this->actingAs($admin)->patch(route('admin.recrutement.toggle', $test->slug))->assertRedirect();

        $this->assertFalse($test->fresh()->actif);
    }
}
