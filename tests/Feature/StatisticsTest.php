<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use App\Services\Attendance\AttendanceInput;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_requiert_une_authentification(): void
    {
        $this->get(route('admin.statistics'))->assertRedirect(route('login'));
    }

    public function test_affiche_les_kpis_globaux(): void
    {
        $user = User::factory()->create();
        $type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
        $event = Event::create([
            'title' => 'Atelier Cybersécurité', 'event_type_id' => $type->id,
            'starts_at' => Carbon::now()->subHour(), 'ends_at' => Carbon::now()->addHour(),
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32), 'public_slug' => 'atelier-stats',
        ]);
        app(AttendanceService::class)->register($event, new AttendanceInput(
            email: 'awa@acs.ci', lastName: 'Koné', firstName: 'Awa',
            phone: '0', company: 'ACS', direction: 'SI', position: 'Analyste',
        ));

        $response = $this->actingAs($user)->get(route('admin.statistics'));

        $response->assertOk()
            ->assertSee('Statistiques')
            ->assertSee('Atelier'); // répartition par type
        $this->assertStringContainsString('1', $response->getContent());
    }
}
