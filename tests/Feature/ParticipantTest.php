<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Person;
use App\Models\User;
use App\Services\Attendance\AttendanceInput;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParticipantTest extends TestCase
{
    use RefreshDatabase;

    private EventType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
    }

    private function event(string $title, int $daysAgo): Event
    {
        return Event::create([
            'title' => $title, 'event_type_id' => $this->type->id,
            'starts_at' => Carbon::now()->subDays($daysAgo), 'ends_at' => Carbon::now()->subDays($daysAgo)->addHour(),
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32), 'public_slug' => Str::slug($title),
        ]);
    }

    public function test_requiert_authentification(): void
    {
        $this->get(route('admin.participants.index'))->assertRedirect(route('login'));
    }

    public function test_liste_seulement_les_personnes_ayant_emarge(): void
    {
        $svc = app(AttendanceService::class);
        $svc->register($this->event('Atelier A', 2), new AttendanceInput(
            email: 'awa@acs.ci', lastName: 'Koné', firstName: 'Awa',
            phone: '0', company: 'ACS', direction: 'SI', position: 'Analyste',
        ));
        // Personne importée sans présence → ne doit pas apparaître.
        Person::create(['email' => 'fantome@acs.ci', 'last_name' => 'Fantôme', 'first_name' => 'Sans', 'is_staff' => true]);

        $this->actingAs(User::factory()->create())->get(route('admin.participants.index'))
            ->assertOk()
            ->assertSee('Awa Koné')
            ->assertDontSee('Sans Fantôme');
    }

    public function test_profil_affiche_historique_et_stats(): void
    {
        $svc = app(AttendanceService::class);
        foreach (['Atelier A' => 2, 'Atelier B' => 5] as $title => $days) {
            $svc->register($this->event($title, $days), new AttendanceInput(
                email: 'awa@acs.ci', lastName: 'Koné', firstName: 'Awa',
                phone: '0', company: 'ACS', direction: 'SI', position: 'Analyste',
            ));
        }
        $person = Person::where('email', 'awa@acs.ci')->firstOrFail();

        $this->actingAs(User::factory()->create())->get(route('admin.participants.show', $person))
            ->assertOk()
            ->assertSee('Awa Koné')
            ->assertSee('Atelier A')
            ->assertSee('Atelier B')
            ->assertSee('Événements'); // libellé KPI
    }
}
