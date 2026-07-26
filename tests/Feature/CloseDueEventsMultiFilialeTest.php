<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Mail\AttendanceConfirmationMail;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Services\Attendance\AttendanceInput;
use App\Services\AttendanceService;
use App\Support\FilialeScoping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le cron `events:close-due` est un contexte SYSTÈME : il doit traiter TOUTES les
 * filiales en un seul passage, indépendamment de tout contexte de cloisonnement
 * (invariant RME-2, verrouillé par `withoutGlobalScope(FilialeScope)` dans la
 * commande). Ces tests exercent ce que le fichier CloseDueEventsTest, mono-filiale,
 * ne couvre pas : plusieurs filiales terminant en même temps, et la robustesse
 * face à un contexte de scoping actif « pollué ».
 */
class CloseDueEventsMultiFilialeTest extends TestCase
{
    use RefreshDatabase;

    private Filiale $filialeA;

    private Filiale $filialeB;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->filialeA = Filiale::factory()->create(['name' => 'ACS Immobilier']);
        $this->filialeB = Filiale::factory()->create(['name' => 'ACS Energie']);
    }

    private function type(Filiale $filiale): EventType
    {
        return EventType::create([
            'filiale_id' => $filiale->id, 'name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0, 'is_active' => true,
        ]);
    }

    private function dueEvent(Filiale $filiale, EventType $type): Event
    {
        return Event::create([
            'filiale_id' => $filiale->id,
            'title' => 'Atelier '.$filiale->name,
            'event_type_id' => $type->id,
            'starts_at' => Carbon::now()->subHours(3),
            'ends_at' => Carbon::now()->subHour(), // terminé → dû à clôture
            'qr_mode' => QrMode::Statique->value,
            'qr_secret' => Str::random(32),
            'public_slug' => 'a-'.Str::lower(Str::random(6)),
        ]);
    }

    private function attend(Event $event, string $email): void
    {
        app(AttendanceService::class)->register($event, new AttendanceInput(
            email: $email, lastName: 'Koné', firstName: 'Awa',
            phone: '0', company: 'ACS', direction: 'SI', position: 'Analyste', isManual: false, manualConfirmed: false,
        ));
    }

    public function test_cloture_des_evenements_de_plusieurs_filiales_en_un_passage(): void
    {
        $eventA = $this->dueEvent($this->filialeA, $this->type($this->filialeA));
        $eventB = $this->dueEvent($this->filialeB, $this->type($this->filialeB));
        $this->attend($eventA, 'awa@acs.ci');
        $this->attend($eventB, 'koffi@acs.ci');

        $this->artisan('events:close-due')->assertSuccessful();

        $this->assertNotNull($eventA->refresh()->closed_at, 'Filiale A doit être clôturée.');
        $this->assertNotNull($eventB->refresh()->closed_at, 'Filiale B doit être clôturée.');
        $this->assertNotNull($eventA->report_email_queued_at);
        $this->assertNotNull($eventB->report_email_queued_at);

        Mail::assertSent(AttendanceConfirmationMail::class, 2);
        Mail::assertSent(AttendanceConfirmationMail::class, fn ($m) => $m->hasTo('awa@acs.ci'));
        Mail::assertSent(AttendanceConfirmationMail::class, fn ($m) => $m->hasTo('koffi@acs.ci'));
    }

    /**
     * Défense en profondeur : même si un contexte de cloisonnement est ACTIF et
     * ciblé sur la filiale A (comme s'il avait été laissé par une requête admin),
     * le cron doit quand même clôturer l'événement de la filiale B. C'est
     * précisément ce que garantit `withoutGlobalScope(FilialeScope)` dans la
     * commande — sans lui, l'événement de B serait invisible et jamais clôturé.
     */
    public function test_le_cron_ignore_un_contexte_de_scoping_actif_et_traite_les_autres_filiales(): void
    {
        // Sans présences : on isole strictement l'invariant « les DEUX passes du
        // cron (clôture + mise en file) ignorent le contexte de cloisonnement ».
        // (Avec présences, le job d'email sync tournerait lui-même sous le scope
        // pollué — artefact de test, pas un comportement de production, le cron
        // n'ayant jamais de scope actif en réel.)
        $eventA = $this->dueEvent($this->filialeA, $this->type($this->filialeA));
        $eventB = $this->dueEvent($this->filialeB, $this->type($this->filialeB));

        // On simule un contexte pollué : scoping actif sur la filiale A uniquement.
        app(FilialeScoping::class)->scopeToFiliale($this->filialeA->id);

        $this->artisan('events:close-due')->assertSuccessful();

        app(FilialeScoping::class)->disable();

        // B ne doit PAS être invisible au cron : clôture ET mise en file email
        // (report_email_queued_at) le traitent malgré le scope ciblé sur A.
        $eventB->refresh();
        $this->assertNotNull($eventB->closed_at, 'La filiale hors contexte doit tout de même être clôturée.');
        $this->assertNotNull($eventB->report_email_queued_at, 'La passe email doit aussi traiter la filiale hors contexte.');
        // A est évidemment traité aussi.
        $this->assertNotNull($eventA->refresh()->closed_at);
    }

    public function test_idempotent_sur_plusieurs_filiales(): void
    {
        $eventA = $this->dueEvent($this->filialeA, $this->type($this->filialeA));
        $eventB = $this->dueEvent($this->filialeB, $this->type($this->filialeB));
        $this->attend($eventA, 'awa@acs.ci');
        $this->attend($eventB, 'koffi@acs.ci');

        $this->artisan('events:close-due')->assertSuccessful();
        $this->artisan('events:close-due')->assertSuccessful();
        $this->artisan('events:close-due')->assertSuccessful();

        // Aucun double email malgré 3 passages, toutes filiales confondues.
        Mail::assertSent(AttendanceConfirmationMail::class, 2);
    }

    public function test_une_filiale_annulee_nempeche_pas_la_cloture_de_lautre(): void
    {
        $eventA = $this->dueEvent($this->filialeA, $this->type($this->filialeA));
        $eventA->update(['cancelled_at' => Carbon::now()->subDay()]);
        $eventB = $this->dueEvent($this->filialeB, $this->type($this->filialeB));
        $this->attend($eventA, 'awa@acs.ci');
        $this->attend($eventB, 'koffi@acs.ci');

        $this->artisan('events:close-due')->assertSuccessful();

        $this->assertNull($eventA->refresh()->closed_at, 'Annulé → jamais clôturé.');
        $this->assertNotNull($eventB->refresh()->closed_at);
        Mail::assertSent(AttendanceConfirmationMail::class, 1);
        Mail::assertNotSent(AttendanceConfirmationMail::class, fn ($m) => $m->hasTo('awa@acs.ci'));
    }
}
