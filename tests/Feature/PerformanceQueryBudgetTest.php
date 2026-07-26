<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\AssertsQueryCount;
use Tests\TestCase;

/**
 * Garde-fou anti-N+1 sur les pages listant un volume variable d'activité :
 * le nombre de requêtes SQL doit rester à budget CONSTANT (eager loading),
 * pas croître linéairement avec le nombre de lignes affichées. Un budget qui
 * explose avec le volume est le symptôme classique d'une relation chargée en
 * boucle (N+1) — invisible avec 2-3 lignes de test, dévastateur en
 * production avec des centaines d'événements/participants.
 */
class PerformanceQueryBudgetTest extends TestCase
{
    use AssertsQueryCount;
    use RefreshDatabase;

    private EventType $type;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
        $this->user = User::factory()->create();
    }

    private function event(int $i): Event
    {
        return Event::create([
            'title' => 'Événement '.$i,
            'event_type_id' => $this->type->id,
            'starts_at' => Carbon::now()->subDays($i),
            'ends_at' => Carbon::now()->subDays($i)->addHour(),
            'qr_mode' => QrMode::Statique->value,
            'qr_secret' => Str::random(32),
            'public_slug' => 'evenement-'.$i.'-'.Str::random(5),
        ]);
    }

    public function test_liste_evenements_reste_a_budget_de_requetes_constant_quel_que_soit_le_volume(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $event = $this->event($i);
            $event->reschedules()->create([
                'old_starts_at' => $event->starts_at, 'old_ends_at' => $event->ends_at,
                'new_starts_at' => $event->starts_at, 'new_ends_at' => $event->ends_at, 'reason' => 'Test',
            ]);
        }
        // Préchauffe hors mesure : la 1re requête authentifiée déclenche une
        // écriture ponctuelle (dernière activité de session) absente des
        // suivantes — sans ça, la comparaison 3 vs 25 lignes serait faussée
        // par cet effet de bord sans rapport avec le volume de données.
        $this->actingAs($this->user)->get(route('admin.events.index'));
        $count3 = $this->countQueries(function (): void {
            $this->actingAs($this->user)->get(route('admin.events.index'))->assertOk();
        });

        for ($i = 4; $i <= 25; $i++) {
            $event = $this->event($i);
            $event->reschedules()->create([
                'old_starts_at' => $event->starts_at, 'old_ends_at' => $event->ends_at,
                'new_starts_at' => $event->starts_at, 'new_ends_at' => $event->ends_at, 'reason' => 'Test',
            ]);
        }
        $count25 = $this->countQueries(function (): void {
            $this->actingAs($this->user)->get(route('admin.events.index'))->assertOk();
        });

        $this->assertSame(
            $count3,
            $count25,
            "La liste des événements exécute {$count3} requêtes avec 3 lignes mais {$count25} avec 25 : "
            .'une relation est probablement chargée en boucle (N+1) au lieu d’être eager-chargée.',
        );
    }

    public function test_galerie_portfolio_reste_a_budget_de_requetes_constant_quel_que_soit_le_volume(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $event = $this->event($i);
            $event->photos()->create(['path' => "reports/{$event->id}/photos/p.jpg", 'position' => 1]);
        }
        $this->actingAs($this->user)->get(route('admin.portfolio')); // préchauffe, cf. commentaire ci-dessus
        $count3 = $this->countQueries(function (): void {
            $this->actingAs($this->user)->get(route('admin.portfolio'))->assertOk();
        });

        for ($i = 4; $i <= 25; $i++) {
            $event = $this->event($i);
            $event->photos()->create(['path' => "reports/{$event->id}/photos/p.jpg", 'position' => 1]);
        }
        $count25 = $this->countQueries(function (): void {
            $this->actingAs($this->user)->get(route('admin.portfolio'))->assertOk();
        });

        $this->assertSame(
            $count3,
            $count25,
            "Le portfolio exécute {$count3} requêtes avec 3 activités mais {$count25} avec 25 : "
            .'une relation est probablement chargée en boucle (N+1) au lieu d’être eager-chargée.',
        );
    }

    public function test_annuaire_participants_reste_a_budget_de_requetes_constant_quel_que_soit_le_volume(): void
    {
        $seedPeople = function (int $from, int $to): void {
            for ($i = $from; $i <= $to; $i++) {
                $event = $this->event($i);
                $person = Person::create([
                    'email' => "personne{$i}@exemple.ci", 'last_name' => 'Nom', 'first_name' => 'Prenom'.$i,
                    'phone' => '+225 0000000'.($i % 10), 'company' => 'ACS', 'direction' => 'DSI',
                ]);
                $event->attendances()->create([
                    'person_id' => $person->id, 'last_name' => 'Nom', 'first_name' => 'Prenom'.$i,
                    'checked_in_at' => Carbon::now(),
                    'reference' => 'PRS-'.Str::upper(Str::random(6)),
                ]);
            }
        };

        $seedPeople(1, 3);
        $this->actingAs($this->user)->get(route('admin.participants.index')); // préchauffe, cf. commentaire plus haut
        $count3 = $this->countQueries(function (): void {
            $this->actingAs($this->user)->get(route('admin.participants.index'))->assertOk();
        });

        $seedPeople(4, 25);
        $count25 = $this->countQueries(function (): void {
            $this->actingAs($this->user)->get(route('admin.participants.index'))->assertOk();
        });

        $this->assertSame(
            $count3,
            $count25,
            "L'annuaire participants exécute {$count3} requêtes avec 3 personnes mais {$count25} avec 25 : "
            .'une relation est probablement chargée en boucle (N+1) au lieu d’être eager-chargée.',
        );
    }
}
