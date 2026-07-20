<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_accessible_a_tout_role_pas_seulement_admin(): void
    {
        $organisateur = User::factory()->create();

        $this->actingAs($organisateur)->get(route('admin.profile.edit'))->assertOk();
    }

    public function test_change_le_mot_de_passe(): void
    {
        $user = User::factory()->create(['password' => Hash::make('ancien-mdp')]);

        $this->actingAs($user)->patch(route('admin.profile.password'), [
            'current_password' => 'ancien-mdp',
            'password' => 'nouveau-mdp-123',
            'password_confirmation' => 'nouveau-mdp-123',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('nouveau-mdp-123', $user->refresh()->password));
    }

    public function test_refuse_un_mauvais_mot_de_passe_actuel(): void
    {
        $user = User::factory()->create(['password' => Hash::make('ancien-mdp')]);

        $this->actingAs($user)->patch(route('admin.profile.password'), [
            'current_password' => 'faux-mdp',
            'password' => 'nouveau-mdp-123',
            'password_confirmation' => 'nouveau-mdp-123',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('ancien-mdp', $user->refresh()->password));
    }

    public function test_refuse_si_confirmation_ne_correspond_pas(): void
    {
        $user = User::factory()->create(['password' => Hash::make('ancien-mdp')]);

        $this->actingAs($user)->patch(route('admin.profile.password'), [
            'current_password' => 'ancien-mdp',
            'password' => 'nouveau-mdp-123',
            'password_confirmation' => 'autre-chose',
        ])->assertSessionHasErrors('password');
    }
}
