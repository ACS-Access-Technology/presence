<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Jobs\SendPartnerAttendanceSheet;
use App\Mail\PartnerAttendanceSheetMail;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Partner;
use App\Models\User;
use App\Services\AttendanceSheetPdf;
use App\Services\QrTokenService;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rencontres partenaires : référentiel des partenaires (marque blanche), choix du
 * destinataire à la création de la rencontre, envoi automatique de la feuille
 * d'émargement PDF à la clôture.
 */
class PartnerMeetingTest extends TestCase
{
    use RefreshDatabase;

    private Filiale $filiale;

    private User $organisateur;

    private EventType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->filiale = Filiale::factory()->create();
        $this->organisateur = User::factory()->forFiliale($this->filiale)->create();
        $this->type = EventType::create([
            'filiale_id' => $this->filiale->id, 'name' => 'Réunion', 'color' => '#2563eb', 'position' => 0,
        ]);
    }

    private function partner(array $overrides = []): Partner
    {
        Storage::disk('public')->put('partners/dgf.png', UploadedFile::fake()->image('dgf.png', 40, 40)->getContent());

        return Partner::factory()->create($overrides + [
            'filiale_id' => $this->filiale->id,
            'name' => 'Direction générale des finances',
            'logo_path' => 'partners/dgf.png',
            'primary_color' => '#0b5e3c',
            'contact_name' => 'Awa Koné',
            'contact_email' => 'awa.kone@dgf.gouv.ci',
        ]);
    }

    private function meeting(Partner $partner, bool $ended = false, QrMode $mode = QrMode::Tournant): Event
    {
        return Event::create([
            'filiale_id' => $this->filiale->id,
            'title' => 'Atelier DGF',
            'event_type_id' => $this->type->id,
            'partner_id' => $partner->id,
            'partner_recipient_name' => 'Awa Koné',
            'partner_recipient_email' => 'awa.kone@dgf.gouv.ci',
            'starts_at' => $ended ? Carbon::now()->subHours(3) : Carbon::now()->subHour(),
            'ends_at' => $ended ? Carbon::now()->subHours(2) : Carbon::now()->addHour(),
            'qr_mode' => $mode->value,
            'qr_secret' => Str::random(32),
            'public_slug' => 'ev-'.Str::random(8),
        ]);
    }

    // ---------- Référentiel partenaires ----------

    public function test_un_organisateur_cree_un_partenaire_avec_son_logo(): void
    {
        $this->actingAs($this->organisateur)->post(route('admin.partners.store'), [
            'name' => 'Direction générale des finances',
            'logo' => UploadedFile::fake()->image('dgf.png', 120, 120),
            'primary_color' => '#0B5E3C',
            'contact_email' => 'awa.kone@dgf.gouv.ci',
        ])->assertRedirect(route('admin.partners.index'));

        $partner = Partner::sole();
        $this->assertSame($this->filiale->id, $partner->filiale_id);
        $this->assertSame('#0b5e3c', $partner->primary_color);
        Storage::disk('public')->assertExists($partner->logo_path);
    }

    public function test_un_logo_svg_est_refuse(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($this->organisateur)->post(route('admin.partners.store'), [
            'name' => 'Partenaire piégé',
            'logo' => $svg,
            'primary_color' => '#0b5e3c',
        ])->assertSessionHasErrors('logo');

        $this->assertSame(0, Partner::count());
    }

    public function test_un_logo_svg_est_refuse_dans_les_parametres(): void
    {
        $admin = User::factory()->filialeAdmin()->forFiliale($this->filiale)->create();
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($admin)->post(route('admin.settings.branding'), [
            'org_name' => 'Filiale', 'logo' => $svg,
        ])->assertSessionHasErrors('logo');
    }

    public function test_les_partenaires_sont_cloisonnes_par_filiale(): void
    {
        $this->partner();
        $autre = User::factory()->forFiliale(Filiale::factory()->create())->create();

        $this->actingAs($autre)->get(route('admin.partners.index'))
            ->assertOk()
            ->assertDontSee('awa.kone@dgf.gouv.ci');
    }

    public function test_un_agent_n_accede_pas_aux_rencontres_partenaires(): void
    {
        $agent = User::factory()->agent()->forFiliale($this->filiale)->create();

        $this->actingAs($agent)->get(route('admin.partners.index'))->assertForbidden();
    }

    // ---------- Création d'une rencontre ----------

    public function test_creer_une_rencontre_exige_le_destinataire_de_la_feuille(): void
    {
        $partner = $this->partner();
        $payload = [
            'title' => 'Atelier DGF', 'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(), 'start' => '09:00', 'end' => '11:00',
            'qr_mode' => 'tournant', 'partner_id' => $partner->id,
        ];

        $this->actingAs($this->organisateur)->post(route('admin.events.store'), $payload)
            ->assertSessionHasErrors('partner_recipient_email');

        $this->actingAs($this->organisateur)->post(route('admin.events.store'), $payload + [
            'partner_recipient_name' => 'Awa Koné', 'partner_recipient_email' => 'awa.kone@dgf.gouv.ci',
        ])->assertSessionHasNoErrors();

        $event = Event::sole();
        $this->assertSame($partner->id, $event->partner_id);
        $this->assertSame('awa.kone@dgf.gouv.ci', $event->partner_recipient_email);
    }

    public function test_un_partenaire_d_une_autre_filiale_est_refuse(): void
    {
        $etranger = Partner::factory()->create(['filiale_id' => Filiale::factory()->create()->id]);

        $this->actingAs($this->organisateur)->post(route('admin.events.store'), [
            'title' => 'Atelier', 'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(), 'start' => '09:00', 'end' => '11:00',
            'qr_mode' => 'tournant', 'partner_id' => $etranger->id,
            'partner_recipient_email' => 'x@exemple.ci',
        ])->assertSessionHasErrors('partner_id');
    }

    public function test_le_formulaire_preselectionne_le_partenaire(): void
    {
        $partner = $this->partner();

        $this->actingAs($this->organisateur)->get(route('admin.events.create', ['partner' => $partner->id]))
            ->assertOk()
            ->assertSee('value="'.$partner->id.'"', false)
            ->assertSee('awa.kone@dgf.gouv.ci');
    }

    // ---------- Marque blanche ----------

    public function test_la_page_d_emargement_est_aux_couleurs_du_partenaire(): void
    {
        $event = $this->meeting($this->partner(), mode: QrMode::Statique);

        $branding = Branding::forEvent($event);
        $this->assertSame('Direction générale des finances', $branding->orgName);
        $this->assertSame('#0b5e3c', $branding->accentColor);

        $this->get(route('public.attendance.show', ['event' => $event->public_slug]))
            ->assertOk()
            ->assertSee('<title>Émargement — Direction générale des finances</title>', false)
            ->assertSee('pour le compte de Direction générale des finances')
            ->assertDontSee('· Presence');
    }

    // ---------- Envoi automatique à la clôture ----------

    public function test_la_cloture_met_en_file_l_envoi_de_la_feuille_une_seule_fois(): void
    {
        Queue::fake();
        $event = $this->meeting($this->partner(), ended: true);

        $this->artisan('events:close-due')->assertSuccessful();
        $this->artisan('events:close-due')->assertSuccessful();

        Queue::assertPushed(SendPartnerAttendanceSheet::class, 1);
        Queue::assertPushed(SendPartnerAttendanceSheet::class, fn ($job) => $job->eventId === $event->id);
    }

    public function test_la_feuille_pdf_part_au_destinataire_choisi_et_une_seule_fois(): void
    {
        Mail::fake();
        $event = $this->meeting($this->partner(), ended: true);
        $event->forceFill(['closed_at' => Carbon::now()])->save();

        (new SendPartnerAttendanceSheet($event->id))->handle(app(AttendanceSheetPdf::class));
        (new SendPartnerAttendanceSheet($event->id))->handle(app(AttendanceSheetPdf::class));

        Mail::assertSent(PartnerAttendanceSheetMail::class, 1);
        Mail::assertSent(PartnerAttendanceSheetMail::class, fn (PartnerAttendanceSheetMail $mail): bool => $mail->hasTo('awa.kone@dgf.gouv.ci'));
        $this->assertNotNull($event->fresh()->partner_sheet_sent_at);
    }

    public function test_le_mail_contient_le_pdf_aux_couleurs_du_partenaire(): void
    {
        $event = $this->meeting($this->partner(), ended: true);
        $pdf = app(AttendanceSheetPdf::class)->renderAll($event);

        $this->assertStringStartsWith('%PDF', $pdf);

        $mail = new PartnerAttendanceSheetMail($event, $pdf);
        $mail->assertHasSubject("Liste d'émargement — Atelier DGF");
        $mail->assertSeeInHtml('Direction générale des finances');
        $mail->assertDontSeeInHtml('Presence');
        $this->assertCount(1, $mail->attachments());
    }

    public function test_le_destinataire_est_corrigeable_avant_la_cloture_seulement(): void
    {
        $event = $this->meeting($this->partner());

        $this->actingAs($this->organisateur)
            ->patch(route('admin.events.partner-recipient', $event), ['partner_recipient_email' => 'bon@dgf.gouv.ci'])
            ->assertRedirect(route('admin.events.show', $event));
        $this->assertSame('bon@dgf.gouv.ci', $event->fresh()->partner_recipient_email);

        $event->forceFill(['closed_at' => Carbon::now()])->save();
        $this->actingAs($this->organisateur)
            ->patch(route('admin.events.partner-recipient', $event), ['partner_recipient_email' => 'autre@exemple.ci'])
            ->assertForbidden();
        $this->assertSame('bon@dgf.gouv.ci', $event->fresh()->partner_recipient_email);
    }

    public function test_la_feuille_ne_part_pas_si_la_reunion_n_est_pas_close(): void
    {
        Mail::fake();
        $event = $this->meeting($this->partner());

        (new SendPartnerAttendanceSheet($event->id))->handle(app(AttendanceSheetPdf::class));

        Mail::assertNothingSent();
    }

    public function test_le_destinataire_d_une_reunion_d_une_autre_filiale_est_intouchable(): void
    {
        $event = $this->meeting($this->partner());
        $intrus = User::factory()->forFiliale(Filiale::factory()->create())->create();

        $response = $this->actingAs($intrus)
            ->patch(route('admin.events.partner-recipient', $event), ['partner_recipient_email' => 'pirate@exemple.ci']);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertSame('awa.kone@dgf.gouv.ci', $event->fresh()->partner_recipient_email);
    }

    public function test_un_partenaire_institutionnel_affiche_l_en_tete_officiel(): void
    {
        $this->actingAs($this->organisateur)->post(route('admin.partners.store'), [
            'name' => 'Direction générale des impôts',
            'logo' => UploadedFile::fake()->image('dgi.png', 120, 120),
            'primary_color' => '#0b5e3c',
            'is_institutional' => '1',
        ])->assertSessionHasNoErrors();

        $partner = Partner::where('name', 'Direction générale des impôts')->sole();
        $this->assertTrue($partner->is_institutional);

        $event = $this->meeting($partner, mode: QrMode::Statique);
        $this->assertTrue(Branding::forEvent($event)->isInstitutional);

        $this->get(route('public.attendance.show', ['event' => $event->public_slug]))
            ->assertOk()
            ->assertSee('armoiries-ci.svg', false)
            ->assertSee("République de Côte d'Ivoire", false)
            ->assertSee('Union – Discipline – Travail');

        $this->assertStringStartsWith('%PDF', app(AttendanceSheetPdf::class)->renderAll($event));
    }

    public function test_un_partenaire_non_institutionnel_n_a_pas_les_armoiries(): void
    {
        $event = $this->meeting($this->partner(), mode: QrMode::Statique);

        $this->get(route('public.attendance.show', ['event' => $event->public_slug]))
            ->assertOk()
            ->assertDontSee('armoiries-ci.svg', false);
    }

    public function test_le_design_registre_est_reserve_aux_partenaires_institutionnels(): void
    {
        $classique = $this->meeting($this->partner(), mode: QrMode::Statique);
        $this->get(route('public.attendance.show', ['event' => $classique->public_slug]))
            ->assertOk()->assertDontSee('theme-registre', false);

        $institution = $this->meeting($this->partner(['name' => 'Trésor public', 'is_institutional' => true]), mode: QrMode::Statique);
        $this->get(route('public.attendance.show', ['event' => $institution->public_slug]))
            ->assertOk()->assertSee('theme-registre', false);
    }

    public function test_une_image_de_fond_de_scene_s_affiche_derriere_la_page(): void
    {
        $this->actingAs($this->organisateur)->post(route('admin.partners.store'), [
            'name' => 'Port autonome',
            'logo' => UploadedFile::fake()->image('logo.png', 80, 80),
            'background' => UploadedFile::fake()->image('scene.jpg', 1600, 900),
            'primary_color' => '#0b5e3c',
        ])->assertSessionHasNoErrors();

        $partner = Partner::where('name', 'Port autonome')->sole();
        Storage::disk('public')->assertExists($partner->background_path);

        $event = $this->meeting($partner, mode: QrMode::Statique);
        $this->get(route('public.attendance.show', ['event' => $event->public_slug]))
            ->assertOk()
            ->assertSee('has-backdrop', false)
            ->assertSee('storage/'.$partner->background_path, false);

        $this->actingAs($this->organisateur)->post(route('admin.partners.update', $partner), [
            'name' => 'Port autonome', 'primary_color' => '#0b5e3c', 'remove_background' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertNull($partner->fresh()->background_path);
    }

    // ---------- Localisation verrouillée / déverrouillée ----------

    /** @return array<string, mixed> */
    private function attendancePayload(Event $event, array $overrides = []): array
    {
        return array_merge([
            'email' => 'participant@exemple.ci', 'last_name' => 'Koné', 'first_name' => 'Awa',
            'phone' => '+225 07 00 00 00 00', 'company' => 'DGF', 'direction' => 'Budget', 'position' => 'Analyste',
            'signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            'ticket' => app(QrTokenService::class)->issueScanTicket($event),
            'consent' => '1',
        ], $overrides);
    }

    public function test_sans_localisation_l_emargement_passe_quand_le_partenaire_l_a_deverrouillee(): void
    {
        Storage::fake('local');
        $event = $this->meeting($this->partner(['require_geolocation' => false]), mode: QrMode::Statique);
        $event->forceFill(['geofence_latitude' => 5.35, 'geofence_longitude' => -4.01, 'geofence_radius_m' => 50])->save();

        $this->postJson(route('public.attendance.store', ['event' => $event->public_slug]), $this->attendancePayload($event))
            ->assertSuccessful();

        $this->assertSame(1, $event->attendances()->count());
    }

    public function test_la_localisation_reste_obligatoire_par_defaut(): void
    {
        Storage::fake('local');
        $event = $this->meeting($this->partner(), mode: QrMode::Statique);

        $this->postJson(route('public.attendance.store', ['event' => $event->public_slug]), $this->attendancePayload($event))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('latitude');
    }
}
