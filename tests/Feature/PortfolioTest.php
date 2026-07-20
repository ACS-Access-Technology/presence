<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortfolioTest extends TestCase
{
    use RefreshDatabase;

    private EventType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
    }

    private function event(string $title): Event
    {
        return Event::create([
            'title' => $title, 'event_type_id' => $this->type->id,
            'starts_at' => Carbon::now()->subDay(), 'ends_at' => Carbon::now()->subDay()->addHour(),
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32),
            'public_slug' => Str::slug($title),
        ]);
    }

    public function test_requiert_authentification(): void
    {
        $this->get(route('admin.portfolio'))->assertRedirect(route('login'));
    }

    public function test_liste_uniquement_les_activites_documentees(): void
    {
        $documented = $this->event('Atelier Documenté');
        $documented->report()->create(['body' => 'Bilan très positif de la session.']);

        $withPhoto = $this->event('Atelier Avec Photo');
        $withPhoto->photos()->create(['path' => 'reports/x/p.jpg', 'position' => 1]);

        $this->event('Atelier Vide'); // ni report, ni doc, ni photo

        $this->actingAs(User::factory()->create())->get(route('admin.portfolio'))
            ->assertOk()
            ->assertSee('Atelier Documenté')
            ->assertSee('Atelier Avec Photo')
            ->assertDontSee('Atelier Vide');
    }
}
