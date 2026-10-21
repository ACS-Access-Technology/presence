<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDirectoryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_champs_d_annuaire_sont_remplissables(): void
    {
        $user = User::factory()->agent()->create([
            'phone' => '+225 0102030405',
            'direction' => 'Direction IT',
            'service' => 'Développement',
            'position' => 'Analyste',
        ]);

        $this->assertSame('Direction IT', $user->fresh()->direction);
        $this->assertTrue($user->isAgent());
    }

    public function test_un_agent_peut_etre_relie_a_une_personne(): void
    {
        $person = Person::factory()->create();
        $user = User::factory()->agent()->create(['person_id' => $person->id]);

        $this->assertTrue($user->person->is($person));
    }
}
