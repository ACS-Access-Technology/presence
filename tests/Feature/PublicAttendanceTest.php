<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Person;
use App\Services\AttendanceService;
use App\Services\Attendance\AttendanceInput;
use App\Services\QrTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private EventType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
    }

    private function makeEvent(QrMode $mode = QrMode::Statique, ?Carbon $start = null, ?Carbon $end = null): Event
    {
        return Event::create([
            'title' => 'Atelier Cybersécurité',
            'event_type_id' => $this->type->id,
            'starts_at' => $start ?? Carbon::now()->subHour(),
            'ends_at' => $end ?? Carbon::now()->addHour(),
            'qr_mode' => $mode->value,
            'qr_secret' => Str::random(32),
            'public_slug' => 'atelier-'.Str::random(6),
        ]);
    }

    private function ticket(Event $event): string
    {
        return app(QrTokenService::class)->issueScanTicket($event);
    }

    /** @return array<string, mixed> */
    private function payload(Event $event, array $overrides = []): array
    {
        return array_merge([
            'email' => 'nouveau@exemple.ci',
            'last_name' => 'Koné',
            'first_name' => 'Awa',
            'phone' => '+225 07 00 00 00 00',
            'company' => 'ACS Consulting',
            'direction' => 'Direction SI',
            'position' => 'Analyste',
            'latitude' => 5.35,
            'longitude' => -4.01,
            'accuracy' => 12.5,
            'signature' => 'data:image/png;base64,'.base64_encode('fakepngdata'),
            'ticket' => $this->ticket($event),
            'consent' => '1',
        ], $overrides);
    }

    public function test_show_affiche_le_formulaire_en_mode_statique(): void
    {
        $event = $this->makeEvent();

        $this->get('/e/'.$event->public_slug)->assertOk()->assertSee('Votre email');
    }

    public function test_show_hors_fenetre_est_bloque(): void
    {
        $event = $this->makeEvent(QrMode::Statique, Carbon::now()->addDay(), Carbon::now()->addDay()->addHours(2));

        $this->get('/e/'.$event->public_slug)->assertOk()->assertSee('pas encore ouvert');
    }

    public function test_show_evenement_annule_est_bloque(): void
    {
        $event = $this->makeEvent();
        $event->update(['cancelled_at' => Carbon::now()]);

        $this->get('/e/'.$event->public_slug)->assertOk()->assertSee('annulé');
    }

    public function test_show_tournant_rejette_un_token_absent_ou_invalide(): void
    {
        $event = $this->makeEvent(QrMode::Tournant);

        $this->get('/e/'.$event->public_slug)->assertOk()->assertSee('QR expiré');
        $this->get('/e/'.$event->public_slug.'?t=faux')->assertOk()->assertSee('QR expiré');
    }

    public function test_show_tournant_accepte_un_token_valide(): void
    {
        $event = $this->makeEvent(QrMode::Tournant);
        $token = app(QrTokenService::class)->currentToken($event)['token'];

        $this->get('/e/'.$event->public_slug.'?t='.$token)->assertOk()->assertSee('Votre email');
    }

    public function test_recognize_reconnait_un_email_connu(): void
    {
        $event = $this->makeEvent();
        Person::create(['email' => 'awa@acs.ci', 'last_name' => 'Koné', 'first_name' => 'Awa', 'company' => 'ACS Consulting']);

        $this->postJson('/e/'.$event->public_slug.'/recognize', ['email' => 'awa@acs.ci'])
            ->assertOk()
            ->assertJsonPath('known', true)
            ->assertJsonPath('person.first_name', 'Awa');
    }

    public function test_recognize_email_inconnu(): void
    {
        $event = $this->makeEvent();

        $this->postJson('/e/'.$event->public_slug.'/recognize', ['email' => 'inconnu@x.ci'])
            ->assertOk()
            ->assertJsonPath('known', false);
    }

    public function test_recognize_detecte_un_chevauchement(): void
    {
        $other = $this->makeEvent();
        $current = $this->makeEvent();
        $person = Person::create(['email' => 'k.ndri@acs.ci', 'last_name' => "N'Dri", 'first_name' => 'Kouassi']);
        app(AttendanceService::class)->register($other, new AttendanceInput(
            email: 'k.ndri@acs.ci', lastName: "N'Dri", firstName: 'Kouassi',
            phone: '0', company: 'ACS', direction: 'SI', position: 'Admin',
        ));

        $this->postJson('/e/'.$current->public_slug.'/recognize', ['email' => 'k.ndri@acs.ci'])
            ->assertOk()
            ->assertJsonPath('overlap.event_title', $other->title);
    }

    public function test_store_enregistre_une_presence_et_la_signature(): void
    {
        $event = $this->makeEvent();

        $response = $this->postJson('/e/'.$event->public_slug, $this->payload($event));

        $response->assertOk()->assertJsonPath('event_title', 'Atelier Cybersécurité');
        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseHas('people', ['email' => 'nouveau@exemple.ci']);
        $path = Event::find($event->id)->attendances()->first()->signature_path;
        Storage::disk('local')->assertExists($path);
    }

    public function test_store_est_idempotent(): void
    {
        $event = $this->makeEvent();

        $ref1 = $this->postJson('/e/'.$event->public_slug, $this->payload($event))->json('reference');
        $ref2 = $this->postJson('/e/'.$event->public_slug, $this->payload($event))->json('reference');

        $this->assertSame($ref1, $ref2);
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_store_refuse_un_ticket_invalide(): void
    {
        $event = $this->makeEvent();

        $this->postJson('/e/'.$event->public_slug, $this->payload($event, ['ticket' => 'bidon']))
            ->assertStatus(419);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_store_refuse_hors_fenetre(): void
    {
        $event = $this->makeEvent(QrMode::Statique, Carbon::now()->addDay(), Carbon::now()->addDay()->addHour());

        $this->postJson('/e/'.$event->public_slug, $this->payload($event))->assertStatus(422);
    }

    public function test_store_exige_geoloc_et_signature(): void
    {
        $event = $this->makeEvent();

        $this->postJson('/e/'.$event->public_slug, $this->payload($event, ['latitude' => '', 'longitude' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['latitude', 'longitude']);

        $this->postJson('/e/'.$event->public_slug, $this->payload($event, ['signature' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['signature']);
    }

    public function test_store_demande_confirmation_en_cas_de_chevauchement(): void
    {
        $other = $this->makeEvent();
        $current = $this->makeEvent();
        app(AttendanceService::class)->register($other, new AttendanceInput(
            email: 'k.ndri@acs.ci', lastName: "N'Dri", firstName: 'Kouassi',
            phone: '0', company: 'ACS', direction: 'SI', position: 'Admin',
        ));

        // Sans confirmation → 409 avec l'événement en conflit.
        $this->postJson('/e/'.$current->public_slug, $this->payload($current, ['email' => 'k.ndri@acs.ci']))
            ->assertStatus(409)
            ->assertJsonPath('overlap.event_title', $other->title);

        // Avec confirmation → présence créée + départ enregistré sur l'autre.
        $this->postJson('/e/'.$current->public_slug, $this->payload($current, ['email' => 'k.ndri@acs.ci', 'confirm_departure' => '1']))
            ->assertOk();
        $this->assertNotNull($other->attendances()->first()->departed_at);
    }
}
