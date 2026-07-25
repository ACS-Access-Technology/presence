<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PersonSource;
use App\Enums\QrMode;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Person;
use App\Services\Attendance\AttendanceInput;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceService $service;

    private EventType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AttendanceService;
        $this->type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
    }

    private function makeEvent(Carbon $start, Carbon $end): Event
    {
        return Event::create([
            'title' => 'Événement test',
            'event_type_id' => $this->type->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'qr_mode' => QrMode::Tournant->value,
            'qr_secret' => Str::random(32),
            'public_slug' => Str::random(10),
        ]);
    }

    private function input(string $email = 'visiteur@exemple.ci'): AttendanceInput
    {
        return new AttendanceInput(
            email: $email,
            lastName: 'Koné',
            firstName: 'Awa',
            phone: '+225 07 00 00 00 00',
            company: 'ACS Consulting',
            direction: 'Direction SI',
            position: 'Analyste',
        );
    }

    public function test_enregistrement_cree_une_presence_et_une_personne(): void
    {
        $event = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());

        $attendance = $this->service->register($event, $this->input());

        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseCount('people', 1);
        $this->assertStringStartsWith('PRS-', $attendance->reference);
        $this->assertSame('ACS Consulting', $attendance->company); // snapshot figé
    }

    public function test_deuxieme_soumission_est_idempotente(): void
    {
        $event = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());

        $first = $this->service->register($event, $this->input());
        $second = $this->service->register($event, $this->input());

        $this->assertDatabaseCount('attendances', 1);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->reference, $second->reference);
    }

    public function test_detecte_un_chevauchement_actif_sur_un_autre_evenement(): void
    {
        $eventA = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());
        $eventB = $this->makeEvent(Carbon::now()->subMinutes(30), Carbon::now()->addHours(2));

        $this->service->register($eventA, $this->input('k.ndri@acs.ci'));
        $person = Person::where('email', 'k.ndri@acs.ci')->firstOrFail();

        $overlap = $this->service->activeOverlap($person, $eventB);

        $this->assertNotNull($overlap);
        $this->assertSame($eventA->id, $overlap->event_id);
    }

    public function test_confirmer_le_depart_cloture_la_presence_precedente(): void
    {
        $eventA = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());
        $eventB = $this->makeEvent(Carbon::now()->subMinutes(30), Carbon::now()->addHours(2));

        $onA = $this->service->register($eventA, $this->input('k.ndri@acs.ci'));

        // La confirmation vit au niveau contrôleur (409) ; register() clôture
        // automatiquement le chevauchement pour garantir une seule présence active.
        $onB = $this->service->register($eventB, $this->input('k.ndri@acs.ci'));

        $this->assertNotNull($onA->refresh()->departed_at);
        $this->assertNull($onB->departed_at);
        $this->assertDatabaseCount('attendances', 2);
    }

    public function test_recurrent_si_presence_sur_evenement_anterieur(): void
    {
        $past = $this->makeEvent(Carbon::now()->subDays(2), Carbon::now()->subDays(2)->addHours(2));
        $today = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());

        $this->service->register($past, $this->input('recurrent@exemple.ci'));
        $person = Person::where('email', 'recurrent@exemple.ci')->firstOrFail();

        $this->assertTrue($this->service->isRecurrent($person, $today));

        $newcomer = Person::create(['email' => 'nouveau@exemple.ci', 'last_name' => 'X', 'first_name' => 'Y']);
        $this->assertFalse($this->service->isRecurrent($newcomer, $today));
    }

    public function test_upsert_ne_degrade_pas_le_statut_personnel(): void
    {
        $staff = Person::create([
            'email' => 'staff@acsgroupe.ci', 'last_name' => 'Bamba', 'first_name' => 'Mariam',
            'is_staff' => true, 'source' => PersonSource::Import,
        ]);

        $event = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());
        $this->service->register($event, $this->input('staff@acsgroupe.ci'));

        $staff->refresh();
        $this->assertTrue($staff->is_staff);
        $this->assertSame(PersonSource::Import, $staff->source);
    }

    public function test_soumission_publique_n_ecrase_pas_la_fiche_existante(): void
    {
        $person = Person::create([
            'email' => 'awa.kone@acsgroupe.ci', 'last_name' => 'Koné', 'first_name' => 'Awa',
            'phone' => '+225 01 00 00 00 00', 'company' => 'ACS Groupe',
        ]);

        $event = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());
        $this->service->register($event, new AttendanceInput(
            email: 'awa.kone@acsgroupe.ci', lastName: 'Autre', firstName: 'Nom',
            phone: '+225 09 99 99 99 99', company: 'Société bidon', direction: 'Falsifié', position: 'Faux',
        ));

        $person->refresh();
        $this->assertSame('Koné', $person->last_name);
        $this->assertSame('+225 01 00 00 00 00', $person->phone);
        $this->assertSame('ACS Groupe', $person->company);
    }

    public function test_course_concurrente_ne_laisse_jamais_deux_presences_actives(): void
    {
        // Reproduit la course entre deux scans concurrents de la MÊME personne vers
        // deux événements SIMULTANÉS. La seconde requête, dont le pré-contrôle (non
        // verrouillé) avait pu voir « pas de chevauchement », appelle register().
        // Sous verrou, register() DOIT re-vérifier l'état à jour et garantir qu'il ne
        // subsiste JAMAIS deux présences actives simultanées : il clôture la première.
        //
        // Limite du test : SQLite :memory: n'autorise pas un vrai parallélisme
        // (connexion unique). On valide donc la LOGIQUE sous verrou — le second appel,
        // voyant la présence active concurrente, résout le conflit — ce que le
        // lockForUpdate() garantit en production (MySQL) en sérialisant réellement les
        // deux transactions (la seconde attend le commit de la première puis relit).
        $eventA = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());
        $eventB = $this->makeEvent(Carbon::now()->subMinutes(30), Carbon::now()->addHours(2));

        $onA = $this->service->register($eventA, $this->input('k.ndri@acs.ci'));
        $onB = $this->service->register($eventB, $this->input('k.ndri@acs.ci'));

        // Les deux présences existent (historique) mais UNE SEULE est active.
        $this->assertDatabaseCount('attendances', 2);
        $this->assertNotNull($onA->refresh()->departed_at, 'La première présence doit être clôturée.');
        $this->assertNull($onB->refresh()->departed_at, 'La présence courante reste active.');
        $activesSimultanees = Attendance::query()
            ->where('person_id', $onA->person_id)
            ->whereNull('departed_at')
            ->count();
        $this->assertSame(1, $activesSimultanees);
    }

    public function test_backfill_sur_evenement_passe_ne_cloture_pas_une_presence_en_cours(): void
    {
        // Enregistrer une présence sur un événement PASSÉ (import/rattrapage) ne doit
        // pas clôturer une présence réellement en cours : la garde anti-chevauchement
        // ne s'applique que si l'événement cible couvre l'instant courant.
        $enCours = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());
        $passe = $this->makeEvent(Carbon::now()->subDays(2), Carbon::now()->subDays(2)->addHours(2));

        $actuel = $this->service->register($enCours, $this->input('k.ndri@acs.ci'));
        $this->service->register($passe, $this->input('k.ndri@acs.ci'));

        $this->assertNull($actuel->refresh()->departed_at, 'La présence en cours ne doit pas être clôturée par un backfill.');
    }

    public function test_creation_concurrente_de_la_meme_personne_reste_idempotente(): void
    {
        // Simule deux premières soumissions strictement simultanées du même email :
        // les deux voient « personne absente », l'une gagne l'INSERT, l'autre viole
        // la contrainte unique(email). On force la course de façon déterministe : un
        // écouteur `creating` insère la « gagnante » en base juste avant que l'INSERT
        // Eloquent ne parte, provoquant la violation d'unicité. register() doit
        // relire la gagnante au lieu de planter (500).
        $event = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());
        $email = Person::normalizeEmail('collision@acs.ci');

        $fired = false;
        Person::creating(function () use (&$fired, $email): void {
            if ($fired) {
                return;
            }
            $fired = true;
            // Insertion brute (bypass Eloquent → ne redéclenche pas `creating`),
            // jouant le rôle de la requête concurrente gagnante.
            DB::table('people')->insert([
                'email' => $email,
                'last_name' => 'Gagnante',
                'first_name' => 'Concurrente',
                'source' => PersonSource::Emargement->value,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        });

        // Chaque méthode de test reconstruit l'application (dispatcher d'événements
        // neuf), et le garde $fired empêche toute re-déclenche : le listener ne fuit
        // pas sur les autres tests.
        $attendance = $this->service->register($event, $this->input('collision@acs.ci'));

        $this->assertSame(1, Person::where('email', $email)->count());
        $this->assertNotNull($attendance->reference);
        $this->assertSame('Gagnante', $attendance->person->last_name);
    }

    public function test_reference_a_une_entropie_suffisante(): void
    {
        // La référence sert aussi de clé d'accès à l'avis (/avis/{reference}) : elle
        // doit être difficile à énumérer. On vérifie une longueur nettement > 5.
        $event = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());
        $attendance = $this->service->register($event, $this->input());

        // Format PRS-XXXXXXXX : au moins 8 caractères aléatoires après le préfixe.
        $this->assertMatchesRegularExpression('/^PRS-[A-Z0-9]{8,}$/', $attendance->reference);
    }

    public function test_marquer_et_annuler_un_depart(): void
    {
        $event = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());
        $attendance = $this->service->register($event, $this->input());

        $this->service->markDeparture($attendance);
        $this->assertNotNull($attendance->refresh()->departed_at);

        $this->service->undoDeparture($attendance);
        $this->assertNull($attendance->refresh()->departed_at);
    }
}
