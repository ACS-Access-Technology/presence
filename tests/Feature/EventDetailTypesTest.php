<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fiche événement vue par un SuperAdmin en « Toutes les filiales » : la fenêtre
 * « Modifier » ne propose que les types de LA filiale de l'événement (sinon les
 * types homonymes de chaque filiale s'empilaient).
 */
class EventDetailTypesTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_fenetre_modifier_ne_propose_que_les_types_de_la_filiale_de_l_evenement(): void
    {
        $a = Filiale::factory()->create();
        $b = Filiale::factory()->create();
        $typeA = EventType::create(['filiale_id' => $a->id, 'name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
        $typeB = EventType::create(['filiale_id' => $b->id, 'name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);

        $event = Event::create([
            'filiale_id' => $a->id, 'title' => 'Atelier test', 'event_type_id' => $typeA->id,
            'starts_at' => Carbon::now()->addDay(), 'ends_at' => Carbon::now()->addDay()->addHour(),
            'qr_mode' => 'tournant', 'qr_secret' => Str::random(32), 'public_slug' => 'ev-'.Str::random(8),
        ]);

        $html = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.events.show', $event))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="event_type_id" value="'.$typeA->id.'"', $html);
        $this->assertStringNotContainsString('name="event_type_id" value="'.$typeB->id.'"', $html);
    }
}
