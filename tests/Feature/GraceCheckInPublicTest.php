<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Mail\AttendanceConfirmationMail;
use App\Models\Event;
use App\Models\EventType;
use App\Services\QrTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lot G — T-ME-23 : QR post-clôture, prouvé DE BOUT EN BOUT via le vrai flux
 * public (`/e/{slug}`), pas seulement la méthode de modèle isOpenForCheckIn().
 *
 * Les tests unitaires existants (GraceCheckInTest) valident la borne au niveau du
 * modèle. Ici on vérifie que le CONTRÔLEUR public applique bien cette borne — donc
 * qu'un visiteur réel peut émarger à +14 min 59 s mais est refusé à +15 min 01 s,
 * que la page affiche le formulaire pendant la grâce (statut « Clos » mais
 * émargement ouvert — découplage § 6.1), et que la clôture prime.
 */
class GraceCheckInPublicTest extends TestCase
{
    use RefreshDatabase;

    private const string TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private EventType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        $this->type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function graceEvent(Carbon $start, Carbon $end, bool $grace = true): Event
    {
        return Event::create([
            'title' => 'Atelier Grace',
            'event_type_id' => $this->type->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'grace_check_in_enabled' => $grace,
            'qr_mode' => QrMode::Statique->value,
            'qr_secret' => Str::random(32),
            'public_slug' => 'grace-'.Str::random(6),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Event $event, string $email): array
    {
        return [
            'email' => $email,
            'last_name' => 'Koné',
            'first_name' => 'Awa',
            'phone' => '+225 07 00 00 00 00',
            'company' => 'ACS Consulting',
            'direction' => 'Direction SI',
            'position' => 'Analyste',
            'latitude' => 5.35,
            'longitude' => -4.01,
            'accuracy' => 12.5,
            'signature' => 'data:image/png;base64,'.self::TINY_PNG_B64,
            'ticket' => app(QrTokenService::class)->issueScanTicket($event),
            'consent' => '1',
        ];
    }

    // =====================================================================
    // Borne exacte, exercée par le vrai endpoint public
    // =====================================================================

    public function test_emargement_public_accepte_a_14min59_pendant_la_grace(): void
    {
        $end = Carbon::parse('2026-07-25 10:00:00');
        $event = $this->graceEvent($end->copy()->subHour(), $end, grace: true);

        Carbon::setTestNow($end->copy()->addMinutes(14)->addSeconds(59));

        $this->postJson('/e/'.$event->public_slug, $this->payload($event, 'a1459@acs.ci'))
            ->assertOk();
        $this->assertDatabaseHas('people', ['email' => 'a1459@acs.ci']);
        $this->assertSame(1, $event->attendances()->count());
    }

    public function test_emargement_public_refuse_a_15min01_apres_la_grace(): void
    {
        $end = Carbon::parse('2026-07-25 10:00:00');
        $event = $this->graceEvent($end->copy()->subHour(), $end, grace: true);

        Carbon::setTestNow($end->copy()->addMinutes(15)->addSeconds(1));

        $this->postJson('/e/'.$event->public_slug, $this->payload($event, 'a1501@acs.ci'))
            ->assertStatus(422);
        $this->assertSame(0, $event->attendances()->count());
    }

    // =====================================================================
    // Découplage statut affiché « Clos » / émargement encore ouvert (§ 6.1)
    // =====================================================================

    public function test_la_page_publique_affiche_le_formulaire_pendant_la_grace(): void
    {
        // now > ends_at → statut « Clos », mais la grâce ouvre l'émargement : le
        // formulaire DOIT s'afficher (le contrôleur s'appuie sur isOpenForCheckIn,
        // pas sur status()). Un blocage ici trahirait un couplage au statut.
        $end = Carbon::parse('2026-07-25 10:00:00');
        $event = $this->graceEvent($end->copy()->subHour(), $end, grace: true);

        Carbon::setTestNow($end->copy()->addMinutes(10));

        $this->get('/e/'.$event->public_slug)
            ->assertOk()
            ->assertSee('Votre email')
            ->assertDontSee('Émargement clôturé');
    }

    public function test_la_page_publique_bloque_le_formulaire_apres_la_grace(): void
    {
        $end = Carbon::parse('2026-07-25 10:00:00');
        $event = $this->graceEvent($end->copy()->subHour(), $end, grace: true);

        Carbon::setTestNow($end->copy()->addMinutes(16));

        $this->get('/e/'.$event->public_slug)
            ->assertOk()
            ->assertSee('Émargement clôturé')
            ->assertDontSee('Votre email');
    }

    // =====================================================================
    // Régression : SANS grâce, fermeture immédiate à ends_at
    // =====================================================================

    public function test_sans_grace_le_formulaire_et_lemargement_ferment_des_ends_at(): void
    {
        $end = Carbon::parse('2026-07-25 10:00:00');
        $event = $this->graceEvent($end->copy()->subHour(), $end, grace: false);

        Carbon::setTestNow($end->copy()->addSeconds(1));

        $this->get('/e/'.$event->public_slug)
            ->assertOk()
            ->assertSee('Émargement clôturé')
            ->assertDontSee('Votre email');

        $this->postJson('/e/'.$event->public_slug, $this->payload($event, 'trop-tard@acs.ci'))
            ->assertStatus(422);
        $this->assertSame(0, $event->attendances()->count());
    }

    // =====================================================================
    // La clôture prime sur la grâce (Q-ME-7), prouvé côté public
    // =====================================================================

    public function test_closed_at_pose_pendant_la_grace_bloque_lemargement_public(): void
    {
        // À +5 min, l'émargement est théoriquement ouvert (grâce). Mais dès que
        // closed_at est posé (cron ou intervention), plus aucun émargement, même
        // dans la fenêtre. On le prouve au niveau de l'endpoint, pas du modèle.
        $end = Carbon::parse('2026-07-25 10:00:00');
        $event = $this->graceEvent($end->copy()->subHour(), $end, grace: true);

        Carbon::setTestNow($end->copy()->addMinutes(5));
        $event->update(['closed_at' => Carbon::now()]);

        $this->get('/e/'.$event->public_slug)
            ->assertOk()
            ->assertDontSee('Votre email');

        $this->postJson('/e/'.$event->public_slug, $this->payload($event, 'apres-cloture@acs.ci'))
            ->assertStatus(422);
        $this->assertSame(0, $event->attendances()->count());
    }

    public function test_annulation_manuelle_pendant_la_grace_ferme_lemargement_public(): void
    {
        // Intervention manuelle réelle (l'organisateur annule) pendant la grâce :
        // l'émargement public se ferme immédiatement.
        $end = Carbon::parse('2026-07-25 10:00:00');
        $event = $this->graceEvent($end->copy()->subHour(), $end, grace: true);

        Carbon::setTestNow($end->copy()->addMinutes(5));
        $event->update(['cancelled_at' => Carbon::now()]);

        $this->get('/e/'.$event->public_slug)
            ->assertOk()
            ->assertSee('annulé');

        $this->postJson('/e/'.$event->public_slug, $this->payload($event, 'annule@acs.ci'))
            ->assertStatus(422);
        $this->assertSame(0, $event->attendances()->count());
    }

    // =====================================================================
    // Email récap : émargement fait PENDANT la grâce via le flux public
    // =====================================================================

    public function test_lemail_recap_part_apres_la_grace_et_inclut_un_emargement_public_de_grace(): void
    {
        $end = Carbon::parse('2026-07-25 10:00:00');
        $event = $this->graceEvent($end->copy()->subHour(), $end, grace: true);

        // Émargement réel d'un visiteur pendant la grâce (10:12).
        Carbon::setTestNow($end->copy()->addMinutes(12));
        $this->postJson('/e/'.$event->public_slug, $this->payload($event, 'visiteur-grace@acs.ci'))
            ->assertOk();

        // Avant la fin de grâce (10:14) : le cron ne clôt pas, aucun email.
        Carbon::setTestNow($end->copy()->addMinutes(14));
        $this->artisan('events:close-due')->assertSuccessful();
        $this->assertNull($event->refresh()->closed_at);
        Mail::assertNothingSent();

        // Après la grâce (10:16) : clôture + email incluant l'émargement de grâce.
        Carbon::setTestNow($end->copy()->addMinutes(16));
        $this->artisan('events:close-due')->assertSuccessful();
        $this->assertNotNull($event->refresh()->closed_at);
        Mail::assertSent(
            AttendanceConfirmationMail::class,
            fn (AttendanceConfirmationMail $mail): bool => $mail->hasTo('visiteur-grace@acs.ci'),
        );
    }
}
