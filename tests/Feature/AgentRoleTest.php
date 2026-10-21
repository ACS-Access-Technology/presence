<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_role_agent_existe_et_a_un_libelle(): void
    {
        $this->assertSame('agent', UserRole::Agent->value);
        $this->assertSame('Agent', UserRole::Agent->label());
    }

    public function test_l_agent_ne_gere_pas_les_parametres(): void
    {
        $this->assertFalse(UserRole::Agent->canManageSettings());
    }

    public function test_un_agent_n_accede_pas_aux_parametres(): void
    {
        $agent = User::factory()->agent()->create();
        $this->actingAs($agent)->get(route('admin.settings.index'))->assertForbidden();
    }
}
