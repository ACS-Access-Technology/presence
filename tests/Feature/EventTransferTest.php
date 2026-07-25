<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventInvitation;
use App\Models\EventReport;
use App\Models\EventSeries;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Person;
use App\Models\ReportDocument;
use App\Models\ReportPhoto;
use App\Models\Scopes\FilialeScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Transfert d'un événement (ou de sa série) entre filiales — SuperAdmin (T-ME-14).
 *
 * Couvre : le transfert simple, la conservation intégrale des données liées
 * (présences, invitations, compte-rendu + documents + photos, référentiel Person),
 * le rejet d'un type non compatible avec la filiale cible, la distinction série
 * entière / séance unique, l'écriture du journal d'audit, le cloisonnement (403
 * pour un non-SuperAdmin) et la NON-contamination avec la réassignation de compte.
 */
final class EventTransferTest extends TestCase
{
    use RefreshDatabase;

    private Filiale $filialeA;

    private Filiale $filialeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filialeA = Filiale::factory()->create(['name' => 'ACS Immobilier']);
        $this->filialeB = Filiale::factory()->create(['name' => 'ACS Energie']);
    }

    private function type(Filiale $filiale, string $name = 'Atelier'): EventType
    {
        return EventType::create([
            'filiale_id' => $filiale->id, 'name' => $name, 'color' => '#7c3aed', 'position' => 0, 'is_active' => true,
        ]);
    }

    private function event(Filiale $filiale, EventType $type, ?int $seriesId = null, ?int $position = null): Event
    {
        return Event::create([
            'filiale_id' => $filiale->id,
            'title' => 'Atelier',
            'event_type_id' => $type->id,
            'starts_at' => Carbon::now()->addDay(),
            'ends_at' => Carbon::now()->addDay()->addHour(),
            'qr_mode' => QrMode::Statique->value,
            'qr_secret' => Str::random(32),
            'public_slug' => 'atelier-'.Str::lower(Str::random(6)),
            'event_series_id' => $seriesId,
            'series_position' => $position,
        ]);
    }

    private function attend(Event $event, string $email): Attendance
    {
        $person = Person::create(['email' => $email, 'last_name' => 'Nom', 'first_name' => 'Prénom']);

        return Attendance::create([
            'event_id' => $event->id,
            'person_id' => $person->id,
            'last_name' => 'Nom',
            'first_name' => 'Prénom',
            'checked_in_at' => Carbon::now(),
            'reference' => 'PRS-'.Str::upper(Str::random(6)),
        ]);
    }

    // ======================================================================
    // Transfert simple
    // ======================================================================

    public function test_super_admin_transfere_un_evenement_simple(): void
    {
        $super = User::factory()->superAdmin()->create();
        $event = $this->event($this->filialeA, $this->type($this->filialeA));
        $targetType = $this->type($this->filialeB, 'Réunion');

        $this->actingAs($super)
            ->post(route('admin.events.transfer', $event), [
                'filiale_id' => $this->filialeB->id,
                'event_type_id' => $targetType->id,
                'scope' => 'seance',
            ])
            ->assertRedirect(route('admin.events.index'));

        $event->refresh();
        $this->assertSame($this->filialeB->id, $event->filiale_id);
        $this->assertSame($targetType->id, $event->event_type_id);
    }

    public function test_le_transfert_conserve_presences_invitations_et_compte_rendu(): void
    {
        $super = User::factory()->superAdmin()->create();
        $type = $this->type($this->filialeA);
        $event = $this->event($this->filialeA, $type);
        $targetType = $this->type($this->filialeB, 'Réunion');

        // Données liées à préserver.
        $attendance = $this->attend($event, 'visiteur@exemple.ci');
        $invitedPerson = Person::create(['email' => 'invite@exemple.ci', 'last_name' => 'I', 'first_name' => 'V']);
        $invitation = EventInvitation::create(['event_id' => $event->id, 'person_id' => $invitedPerson->id]);
        $report = EventReport::create(['event_id' => $event->id, 'body' => 'Compte-rendu détaillé']);
        $document = ReportDocument::create([
            'event_id' => $event->id, 'original_name' => 'bilan.pdf', 'path' => 'reports/1/bilan.pdf',
            'mime' => 'application/pdf', 'size' => 1024,
        ]);
        $photo = ReportPhoto::create(['event_id' => $event->id, 'path' => 'reports/1/photo.jpg', 'position' => 0]);

        $peopleBefore = Person::count();

        $this->actingAs($super)
            ->post(route('admin.events.transfer', $event), [
                'filiale_id' => $this->filialeB->id,
                'event_type_id' => $targetType->id,
                'scope' => 'seance',
            ])
            ->assertRedirect(route('admin.events.index'));

        // Rien n'est perdu ni orphelin : tout suit l'événement (via event_id).
        $this->assertSame($event->id, $attendance->refresh()->event_id);
        $this->assertSame($event->id, $invitation->refresh()->event_id);
        $this->assertSame($event->id, $report->refresh()->event_id);
        $this->assertSame('Compte-rendu détaillé', $report->body);
        $this->assertSame($event->id, $document->refresh()->event_id);
        $this->assertSame('reports/1/bilan.pdf', $document->path); // stockage inchangé
        $this->assertSame($event->id, $photo->refresh()->event_id);

        // Le référentiel Person (unifié holding) n'est pas altéré.
        $this->assertSame($peopleBefore, Person::count());
    }

    // ======================================================================
    // Rejet : type non compatible avec la filiale cible
    // ======================================================================

    public function test_transfert_rejete_si_le_type_nappartient_pas_a_la_filiale_cible(): void
    {
        $super = User::factory()->superAdmin()->create();
        $typeA = $this->type($this->filialeA);
        $event = $this->event($this->filialeA, $typeA);
        $this->type($this->filialeB, 'Réunion'); // un type existe dans B, mais on soumet celui de A

        $this->actingAs($super)
            ->from(route('admin.events.show', $event))
            ->post(route('admin.events.transfer', $event), [
                'filiale_id' => $this->filialeB->id,
                'event_type_id' => $typeA->id, // type de la filiale A → incompatible avec B
                'scope' => 'seance',
            ])
            ->assertRedirect(route('admin.events.show', $event))
            ->assertSessionHasErrors('event_type_id');

        // L'événement n'a pas bougé.
        $event->refresh();
        $this->assertSame($this->filialeA->id, $event->filiale_id);
        $this->assertSame($typeA->id, $event->event_type_id);
    }

    public function test_transfert_rejete_si_la_filiale_cible_na_aucun_type(): void
    {
        $super = User::factory()->superAdmin()->create();
        $typeA = $this->type($this->filialeA);
        $event = $this->event($this->filialeA, $typeA);
        // filialeB n'a AUCUN type : impossible de fournir un event_type_id valide.

        $this->actingAs($super)
            ->from(route('admin.events.show', $event))
            ->post(route('admin.events.transfer', $event), [
                'filiale_id' => $this->filialeB->id,
                'event_type_id' => $typeA->id,
                'scope' => 'seance',
            ])
            ->assertSessionHasErrors('event_type_id');

        $this->assertSame($this->filialeA->id, $event->refresh()->filiale_id);
    }

    public function test_transfert_rejete_vers_la_meme_filiale(): void
    {
        $super = User::factory()->superAdmin()->create();
        $typeA = $this->type($this->filialeA);
        $event = $this->event($this->filialeA, $typeA);

        $this->actingAs($super)
            ->from(route('admin.events.show', $event))
            ->post(route('admin.events.transfer', $event), [
                'filiale_id' => $this->filialeA->id,
                'event_type_id' => $typeA->id,
                'scope' => 'seance',
            ])
            ->assertSessionHasErrors('filiale_id');
    }

    // ======================================================================
    // Série entière vs séance unique
    // ======================================================================

    public function test_transfert_de_toute_la_serie_deplace_toutes_les_seances(): void
    {
        $super = User::factory()->superAdmin()->create();
        $typeA = $this->type($this->filialeA);
        $series = EventSeries::create(['title' => 'Atelier', 'event_type_id' => $typeA->id]);
        $s1 = $this->event($this->filialeA, $typeA, $series->id, 1);
        $s2 = $this->event($this->filialeA, $typeA, $series->id, 2);
        $s3 = $this->event($this->filialeA, $typeA, $series->id, 3);
        $targetType = $this->type($this->filialeB, 'Réunion');

        $this->actingAs($super)
            ->post(route('admin.events.transfer', $s1), [
                'filiale_id' => $this->filialeB->id,
                'event_type_id' => $targetType->id,
                'scope' => 'series',
            ])
            ->assertRedirect(route('admin.events.index'));

        foreach ([$s1, $s2, $s3] as $seance) {
            $seance->refresh();
            $this->assertSame($this->filialeB->id, $seance->filiale_id, 'chaque séance suit la série');
            $this->assertSame($targetType->id, $seance->event_type_id);
            $this->assertSame($series->id, $seance->event_series_id, 'la série reste intacte');
        }

        // Le gabarit de série est aligné sur le type cible.
        $this->assertSame($targetType->id, $series->refresh()->event_type_id);
    }

    public function test_transfert_dune_seule_seance_la_detache_de_sa_serie(): void
    {
        $super = User::factory()->superAdmin()->create();
        $typeA = $this->type($this->filialeA);
        $series = EventSeries::create(['title' => 'Atelier', 'event_type_id' => $typeA->id]);
        $s1 = $this->event($this->filialeA, $typeA, $series->id, 1);
        $s2 = $this->event($this->filialeA, $typeA, $series->id, 2);
        $targetType = $this->type($this->filialeB, 'Réunion');

        $this->actingAs($super)
            ->post(route('admin.events.transfer', $s1), [
                'filiale_id' => $this->filialeB->id,
                'event_type_id' => $targetType->id,
                'scope' => 'seance',
            ])
            ->assertRedirect(route('admin.events.index'));

        // La séance transférée est détachée et dans la filiale cible.
        $s1->refresh();
        $this->assertSame($this->filialeB->id, $s1->filiale_id);
        $this->assertSame($targetType->id, $s1->event_type_id);
        $this->assertNull($s1->event_series_id);
        $this->assertNull($s1->series_position);

        // L'autre séance n'a pas bougé : toujours dans A, toujours dans la série.
        $s2->refresh();
        $this->assertSame($this->filialeA->id, $s2->filiale_id);
        $this->assertSame($series->id, $s2->event_series_id);
        // Le gabarit de série n'est PAS modifié quand on ne déplace qu'une séance.
        $this->assertSame($typeA->id, $series->refresh()->event_type_id);
    }

    // ======================================================================
    // Journal d'audit
    // ======================================================================

    public function test_le_transfert_ecrit_une_entree_dans_le_journal_daudit(): void
    {
        $super = User::factory()->superAdmin()->create(['name' => 'Chef', 'email' => 'chef@acs.ci']);
        $typeA = $this->type($this->filialeA);
        $event = $this->event($this->filialeA, $typeA);
        $targetType = $this->type($this->filialeB, 'Réunion');

        $this->actingAs($super)->post(route('admin.events.transfer', $event), [
            'filiale_id' => $this->filialeB->id,
            'event_type_id' => $targetType->id,
            'scope' => 'seance',
        ]);

        $log = AuditLog::where('action', 'event.transferred')->firstOrFail();

        $this->assertSame($super->id, $log->user_id);
        $this->assertSame('chef@acs.ci', $log->actor_email);
        $this->assertSame('Chef', $log->actor_name);
        $this->assertSame($event->id, $log->subject_id);
        $this->assertSame($this->filialeA->id, $log->before['filiale_id']);
        $this->assertSame($this->filialeB->id, $log->after['filiale_id']);
        $this->assertSame($targetType->id, $log->after['event_type_id']);
        $this->assertNotNull($log->created_at);
    }

    // ======================================================================
    // Cloisonnement : seul le SuperAdmin transfère
    // ======================================================================

    public function test_un_admin_filiale_ne_peut_pas_transferer(): void
    {
        $admin = User::factory()->filialeAdmin()->forFiliale($this->filialeA)->create();
        $typeA = $this->type($this->filialeA);
        $event = $this->event($this->filialeA, $typeA);
        $targetType = $this->type($this->filialeB, 'Réunion');

        $this->actingAs($admin)->post(route('admin.events.transfer', $event), [
            'filiale_id' => $this->filialeB->id,
            'event_type_id' => $targetType->id,
            'scope' => 'seance',
        ])->assertForbidden();

        $this->assertSame($this->filialeA->id, $event->refresh()->filiale_id);
    }

    public function test_un_organisateur_ne_peut_pas_transferer(): void
    {
        $orga = User::factory()->forFiliale($this->filialeA)->create();
        $typeA = $this->type($this->filialeA);
        $event = $this->event($this->filialeA, $typeA);
        $targetType = $this->type($this->filialeB, 'Réunion');

        $this->actingAs($orga)->post(route('admin.events.transfer', $event), [
            'filiale_id' => $this->filialeB->id,
            'event_type_id' => $targetType->id,
            'scope' => 'seance',
        ])->assertForbidden();

        $this->assertSame($this->filialeA->id, $event->refresh()->filiale_id);
    }

    // ======================================================================
    // Indépendance vis-à-vis de la réassignation de compte
    // ======================================================================

    public function test_reassigner_un_compte_ne_transfere_pas_ses_evenements(): void
    {
        $super = User::factory()->superAdmin()->create();
        $orga = User::factory()->forFiliale($this->filialeA)->create();
        $event = $this->event($this->filialeA, $this->type($this->filialeA));
        $event->update(['created_by' => $orga->id]);

        // On réassigne le COMPTE de la filiale A vers B.
        $this->actingAs($super)->postJson(route('admin.settings.accounts.reassign', $orga), [
            'filiale_id' => $this->filialeB->id,
        ])->assertOk();

        // L'événement déjà créé NE suit PAS le compte (opérations découplées).
        $this->assertSame($this->filialeB->id, $orga->refresh()->filiale_id);
        $this->assertSame($this->filialeA->id, $event->refresh()->filiale_id);
    }

    // ======================================================================
    // Bonus : le service lève le scope pour ne rater aucune séance
    // ======================================================================

    public function test_le_service_ne_rate_aucune_seance_meme_hors_scope(): void
    {
        // Garantit que le comptage des séances d'une série n'est pas amputé par un
        // éventuel global scope résiduel.
        $typeA = $this->type($this->filialeA);
        $series = EventSeries::create(['title' => 'Atelier', 'event_type_id' => $typeA->id]);
        $this->event($this->filialeA, $typeA, $series->id, 1);
        $this->event($this->filialeA, $typeA, $series->id, 2);

        $count = Event::withoutGlobalScope(FilialeScope::class)
            ->where('event_series_id', $series->id)
            ->count();

        $this->assertSame(2, $count);
    }
}
