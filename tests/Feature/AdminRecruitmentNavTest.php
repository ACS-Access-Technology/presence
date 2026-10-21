<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRecruitmentNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_lien_recrutement_est_visible_pour_un_organisateur(): void
    {
        $user = User::factory()->create(); // Organisateur par défaut (UserFactory)

        $this->actingAs($user)->followingRedirects()->get(route('admin.dashboard'))
            ->assertSee(route('admin.recrutement.index'), false);
    }

    public function test_le_lien_recrutement_est_absent_pour_un_agent(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)->followingRedirects()->get(route('admin.dashboard'))
            ->assertDontSee(route('admin.recrutement.index'), false);
    }
}
