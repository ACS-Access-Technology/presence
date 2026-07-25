<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EventPolicy (T-ME-06) : seconde barrière d'isolation (défense en profondeur).
 */
class EventPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Filiale $filialeA;

    private Filiale $filialeB;

    private Event $eventB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filialeA = Filiale::factory()->create();
        $this->filialeB = Filiale::factory()->create();
        $type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);

        $this->eventB = Event::create([
            'filiale_id' => $this->filialeB->id,
            'title' => 'Beta',
            'event_type_id' => $type->id,
            'starts_at' => Carbon::now(),
            'ends_at' => Carbon::now()->addHour(),
            'qr_mode' => QrMode::Statique->value,
            'qr_secret' => Str::random(32),
            'public_slug' => 'beta-'.Str::random(5),
        ]);
    }

    public function test_acces_croise_refuse_pour_chaque_action(): void
    {
        $orgaA = User::factory()->forFiliale($this->filialeA)->create();

        foreach (['view', 'update', 'export', 'delete'] as $action) {
            $this->assertFalse($orgaA->can($action, $this->eventB), "action {$action} devrait être refusée A→B");
        }
    }

    public function test_acces_meme_filiale_autorise(): void
    {
        $orgaB = User::factory()->forFiliale($this->filialeB)->create();

        foreach (['view', 'update', 'export', 'delete'] as $action) {
            $this->assertTrue($orgaB->can($action, $this->eventB), "action {$action} devrait être autorisée B→B");
        }
    }

    public function test_super_admin_toujours_autorise(): void
    {
        $super = User::factory()->superAdmin()->create();

        foreach (['view', 'update', 'export', 'delete'] as $action) {
            $this->assertTrue($super->can($action, $this->eventB));
        }
    }

    public function test_compte_sans_filiale_non_super_refuse(): void
    {
        $broken = User::factory()->create(['role' => UserRole::Organisateur, 'filiale_id' => null]);

        $this->assertFalse($broken->can('view', $this->eventB));
    }
}
