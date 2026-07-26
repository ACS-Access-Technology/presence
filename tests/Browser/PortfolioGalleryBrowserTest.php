<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Enums\QrMode;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Parcours navigateur réel (E2E) de la galerie photo du Portfolio : ouverture
 * de la visionneuse plein écran au clic, navigation entre photos, fermeture.
 * Interaction purement client (JS) — le test Feature ne peut pas la prouver,
 * seul un vrai navigateur le peut. `DatabaseTruncation` : voir le commentaire
 * de `AdminLoginTest` (Dusk = processus séparé, `RefreshDatabase` inutilisable).
 */
class PortfolioGalleryBrowserTest extends DuskTestCase
{
    use DatabaseTruncation;

    private User $user;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        // `DatabaseTruncation` tronque aussi `filiales` sans rejouer l'insertion
        // de la filiale par défaut faite dans la migration — on la recrée ici.
        Filiale::firstOrCreate(['slug' => Filiale::DEFAULT_SLUG], ['name' => 'ACS Groupe', 'is_active' => true]);

        $type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
        $this->user = User::factory()->create();
        $this->event = Event::create([
            'title' => 'Atelier Dusk',
            'event_type_id' => $type->id,
            'starts_at' => Carbon::now()->subDay(),
            'ends_at' => Carbon::now()->subDay()->addHour(),
            'qr_mode' => QrMode::Statique->value,
            'qr_secret' => Str::random(32),
            'public_slug' => 'atelier-dusk-'.Str::random(5),
        ]);
        foreach (range(1, 3) as $position) {
            $this->event->photos()->create(['path' => "reports/{$this->event->id}/photos/{$position}.jpg", 'position' => $position]);
        }
    }

    public function test_ouvre_navigue_et_ferme_la_visionneuse_photo(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->loginAs($this->user)
                ->visit('/admin/portfolio/'.$this->event->id)
                ->assertSee('Atelier Dusk')
                ->waitFor('.pfg-cell')
                ->click('.pfg-cell')
                ->waitFor('#pfg-lightbox:not([hidden])')
                ->assertSeeIn('#pfg-lightbox-count', '1 / 3')
                ->click('.pfg-lightbox__nav--next')
                ->assertSeeIn('#pfg-lightbox-count', '2 / 3')
                ->click('.pfg-lightbox__nav--next')
                ->assertSeeIn('#pfg-lightbox-count', '3 / 3')
                ->click('.pfg-lightbox__nav--prev')
                ->assertSeeIn('#pfg-lightbox-count', '2 / 3')
                // Cible le bouton fermer (qui a le focus à l'ouverture, cf.
                // piège de focus) : `keys('body', ...)` littéral donnerait un
                // sélecteur invalide "body body", Dusk préfixant déjà 'body'.
                ->keys('.pfg-lightbox__close', '{escape}')
                ->pause(200)
                ->assertAttribute('#pfg-lightbox', 'hidden', 'true');
        });
    }
}
