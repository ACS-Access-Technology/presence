<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Une activité documentée par un compte-rendu/document SANS photo ne doit pas
     * pointer vers la galerie photo (qui serait vide et trompeuse) : le clic doit
     * mener directement au contenu de l'activité. Régression détectée après le
     * passage de la carte vers `admin.portfolio.show` (galerie photo dédiée).
     */
    public function test_une_carte_sans_photo_pointe_vers_le_contenu_pas_vers_la_galerie(): void
    {
        $event = $this->event('Atelier Compte Rendu Seul');
        $event->report()->create(['body' => 'Bilan sans aucune photo jointe.']);

        $html = $this->actingAs(User::factory()->create())->get(route('admin.portfolio'))
            ->assertOk()
            ->assertSee('Voir le contenu')
            ->getContent();

        $this->assertStringContainsString(route('admin.events.show', $event).'#cr', $html);
        $this->assertStringNotContainsString(route('admin.portfolio.show', $event), $html);
    }

    /**
     * Non-régression du bug corrigé en d20eaf1 : `index()` n'eager-chargeait pas
     * la relation `report`, ce qui déclenchait une LazyLoadingViolationException
     * dès qu'un événement documenté existait (masqué tant que le Portfolio restait
     * vide). `shouldBeStrict` n'est actif qu'en local, jamais en test : on force
     * donc explicitement le mode strict pour reproduire la condition de l'incident.
     */
    public function test_index_eager_charge_le_report_sans_lazy_loading(): void
    {
        $documented = $this->event('Atelier Bilan');
        $documented->report()->create(['body' => 'Bilan complet de la session sans caractere special']);

        Model::preventLazyLoading(true);

        try {
            $this->actingAs(User::factory()->create())->get(route('admin.portfolio'))
                ->assertOk()
                ->assertSee('Bilan complet de la session');
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    // ---- show() : galerie photo par activité ----

    public function test_show_requiert_authentification(): void
    {
        $event = $this->event('Atelier Prive');
        $this->get(route('admin.portfolio.show', $event))->assertRedirect(route('login'));
    }

    public function test_show_affiche_letat_vide_sans_photo(): void
    {
        // Un événement peut être documenté par un compte-rendu/document sans photo :
        // la galerie doit alors afficher son état vide, pas planter.
        $event = $this->event('Atelier Sans Photo');
        $event->report()->create(['body' => 'Compte-rendu seul.']);

        $this->actingAs(User::factory()->create())->get(route('admin.portfolio.show', $event))
            ->assertOk()
            ->assertSee('Aucune photo pour cette activité');
    }

    public function test_show_ordonne_les_photos_par_position(): void
    {
        $event = $this->event('Atelier Galerie');
        // Insérées dans le désordre : l'ordre d'affichage doit suivre `position`.
        $troisieme = $event->photos()->create(['path' => 'reports/x/c.jpg', 'position' => 3]);
        $premiere = $event->photos()->create(['path' => 'reports/x/a.jpg', 'position' => 1]);
        $deuxieme = $event->photos()->create(['path' => 'reports/x/b.jpg', 'position' => 2]);

        $html = $this->actingAs(User::factory()->create())
            ->get(route('admin.portfolio.show', $event))
            ->assertOk()
            ->getContent();

        $posPremiere = strpos($html, $premiere->url());
        $posDeuxieme = strpos($html, $deuxieme->url());
        $posTroisieme = strpos($html, $troisieme->url());

        $this->assertNotFalse($posPremiere);
        $this->assertTrue(
            $posPremiere < $posDeuxieme && $posDeuxieme < $posTroisieme,
            'Les photos doivent être rendues dans l’ordre croissant de position.',
        );
    }

    public function test_show_dun_evenement_dune_autre_filiale_renvoie_404(): void
    {
        // Événement dans la filiale par défaut (holding).
        $event = $this->event('Atelier Holding');

        // Un AdminFiliale d'une AUTRE filiale ne doit pas y accéder (global scope
        // → 404 sur le route-model binding, jamais une page vide).
        $autreFiliale = Filiale::factory()->create(['name' => 'ACS Energie']);
        $adminAutre = User::factory()->filialeAdmin()->forFiliale($autreFiliale)->create();

        $this->actingAs($adminAutre)->get(route('admin.portfolio.show', $event))
            ->assertNotFound();
    }

    public function test_show_super_admin_accede_a_toute_filiale(): void
    {
        // Garde-fou : prouve que le 404 ci-dessus vient bien du scope et non d'une
        // route cassée qui répondrait 404 pour tout le monde.
        $event = $this->event('Atelier Holding');
        $event->photos()->create(['path' => 'reports/x/a.jpg', 'position' => 1]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.portfolio.show', $event))
            ->assertOk()
            ->assertSee('Atelier Holding');
    }
}
