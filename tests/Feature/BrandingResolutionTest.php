<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Mail\AttendanceConfirmationMail;
use App\Mail\EventInvitationMail;
use App\Mail\EventRescheduledMail;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Person;
use App\Models\Setting;
use App\Models\User;
use App\Support\Branding;
use App\Support\FilialeScoping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Vérifie que l'identité visuelle (nom, logo, couleur d'accent) affichée sur les
 * surfaces publiques et dans les emails est bien celle de la FILIALE de
 * l'événement — jamais un défaut holding, jamais celle d'une autre filiale, et
 * jamais dérivée du contexte de session (invalide pour un visiteur anonyme ou un
 * job en file). Couvre aussi le lien de l'email d'invitation selon le mode QR.
 */
class BrandingResolutionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crée une filiale avec un branding PROPRE (n'hérite pas de la holding) et
     * renvoie son id.
     */
    private function brandedFiliale(string $name, string $accent, string $logoPath): int
    {
        $filiale = Filiale::factory()->create(['name' => $name]);

        Setting::set('brand_inherit', '0', $filiale->id);
        Setting::set('org_name', $name, $filiale->id);
        Setting::set('accent_color', $accent, $filiale->id);
        Setting::set('logo_path', $logoPath, $filiale->id);

        return $filiale->id;
    }

    /** Crée un événement rattaché à une filiale, ouvert à l'émargement par défaut. */
    private function eventIn(int $filialeId, QrMode $mode = QrMode::Statique, bool $open = true): Event
    {
        $type = EventType::create([
            'filiale_id' => $filialeId, 'name' => 'Type '.Str::random(4), 'color' => '#123456', 'position' => 0,
        ]);

        return Event::create([
            'filiale_id' => $filialeId,
            'title' => 'Événement '.Str::random(4),
            'event_type_id' => $type->id,
            'starts_at' => $open ? Carbon::now()->subHour() : Carbon::now()->addDay(),
            'ends_at' => $open ? Carbon::now()->addHour() : Carbon::now()->addDay()->addHour(),
            'qr_mode' => $mode->value,
            'qr_secret' => Str::random(32),
            'public_slug' => 'ev-'.Str::random(8),
        ]);
    }

    // ---------- Presenter ----------

    public function test_presenter_resout_le_branding_de_la_filiale(): void
    {
        $alpha = $this->brandedFiliale('Filiale Alpha', '#aa0000', 'branding/alpha.png');
        $event = $this->eventIn($alpha);

        $branding = Branding::forEvent($event);

        $this->assertSame('Filiale Alpha', $branding->orgName);
        $this->assertSame('#aa0000', $branding->accentColor);
        $this->assertStringContainsString('/storage/branding/alpha.png', $branding->logoUrl);
        $this->assertTrue($branding->hasCustomLogo);
    }

    public function test_presenter_repli_holding_quand_la_filiale_herite(): void
    {
        // Filiale sans brand_inherit = 0 : hérite du branding holding (par défaut).
        $filiale = Filiale::factory()->create(['name' => 'Filiale Héritière']);
        Setting::set('org_name', 'Ne doit pas apparaître', $filiale->id);
        $event = $this->eventIn($filiale->id);

        $branding = Branding::forEvent($event);

        $this->assertSame('ACS Groupe', $branding->orgName);
        $this->assertFalse($branding->hasCustomLogo);
        $this->assertStringContainsString('assets/logo-acs-groupe.png', $branding->logoUrl);
        $this->assertNull($branding->accentColor);
        $this->assertSame(Branding::DEFAULT_ACCENT, $branding->accentColorOrDefault());
    }

    public function test_presenter_ignore_le_contexte_de_session(): void
    {
        $alpha = $this->brandedFiliale('Filiale Alpha', '#aa0000', 'branding/alpha.png');
        $beta = $this->brandedFiliale('Filiale Beta', '#00bb00', 'branding/beta.png');
        $eventBeta = $this->eventIn($beta);

        // Un SuperAdmin a un contexte de scoping actif sur Alpha : il ne doit PAS
        // teinter la résolution du branding d'un événement de Beta.
        app(FilialeScoping::class)->scopeToFiliale($alpha);

        $branding = Branding::forEvent($eventBeta);

        $this->assertSame('Filiale Beta', $branding->orgName);
        $this->assertSame('#00bb00', $branding->accentColor);
    }

    public function test_presenter_refuse_une_couleur_non_hex(): void
    {
        $filiale = Filiale::factory()->create(['name' => 'Filiale X']);
        Setting::set('brand_inherit', '0', $filiale->id);
        Setting::set('accent_color', 'red; } body{display:none', $filiale->id);
        $event = $this->eventIn($filiale->id);

        // Défense en profondeur : une valeur non `#rrggbb` (donnée altérée) est
        // rejetée avant toute injection dans du CSS inline.
        $this->assertNull(Branding::forEvent($event)->accentColor);
    }

    // ---------- Page publique d'émargement ----------

    public function test_page_publique_affiche_le_branding_de_la_filiale(): void
    {
        $alpha = $this->brandedFiliale('Filiale Alpha', '#aa0000', 'branding/alpha.png');
        $beta = $this->brandedFiliale('Filiale Beta', '#00bb00', 'branding/beta.png');
        $eventAlpha = $this->eventIn($alpha);
        $this->eventIn($beta);

        $html = $this->get(route('public.attendance.show', ['event' => $eventAlpha->public_slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Filiale Alpha', $html);
        $this->assertStringContainsString('/storage/branding/alpha.png', $html);
        $this->assertStringContainsString('#aa0000', $html);
        // Jamais le branding d'une autre filiale ni le logo holding par défaut.
        $this->assertStringNotContainsString('Filiale Beta', $html);
        $this->assertStringNotContainsString('assets/logo-acs-groupe.png', $html);
    }

    public function test_ecran_qr_invalide_conserve_le_branding_de_la_filiale(): void
    {
        $alpha = $this->brandedFiliale('Filiale Alpha', '#aa0000', 'branding/alpha.png');
        $event = $this->eventIn($alpha, QrMode::Tournant);

        // Mode tournant sans token → écran « QR expiré », qui doit rester habillé
        // aux couleurs de la filiale.
        $html = $this->get(route('public.attendance.show', ['event' => $event->public_slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('QR expiré', $html);
        $this->assertStringContainsString('Filiale Alpha', $html);
        $this->assertStringContainsString('/storage/branding/alpha.png', $html);
    }

    public function test_manifest_pwa_utilise_le_logo_et_le_nom_de_la_filiale(): void
    {
        $alpha = $this->brandedFiliale('Filiale Alpha', '#aa0000', 'branding/alpha.png');
        $event = $this->eventIn($alpha);

        $this->getJson(route('public.attendance.manifest', ['event' => $event->public_slug]))
            ->assertOk()
            ->assertJsonPath('name', $event->title.' — Filiale Alpha')
            ->assertJsonPath('theme_color', '#aa0000')
            ->assertJsonPath('icons.0.src', fn (string $src): bool => str_contains($src, '/storage/branding/alpha.png'));
    }

    public function test_manifest_pwa_repli_logo_holding_si_filiale_sans_logo(): void
    {
        // Filiale par défaut (holding), aucun branding configuré → repli holding.
        $event = $this->eventIn(Filiale::defaultId());

        $this->getJson(route('public.attendance.manifest', ['event' => $event->public_slug]))
            ->assertOk()
            ->assertJsonPath('name', $event->title.' — ACS Groupe')
            ->assertJsonPath('icons.0.src', fn (string $src): bool => str_contains($src, 'assets/logo-acs-groupe.png'));
    }

    // ---------- Vues QR côté organisateur (projection / impression) ----------

    public function test_projection_affiche_le_branding_de_la_filiale(): void
    {
        $alpha = $this->brandedFiliale('Filiale Alpha', '#aa0000', 'branding/alpha.png');
        $event = $this->eventIn($alpha, QrMode::Tournant);

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.events.projection', $event))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Filiale Alpha', $html);
        $this->assertStringContainsString('/storage/branding/alpha.png', $html);
        $this->assertStringContainsString('#aa0000', $html);
    }

    public function test_impression_qr_affiche_le_branding_de_la_filiale(): void
    {
        $alpha = $this->brandedFiliale('Filiale Alpha', '#aa0000', 'branding/alpha.png');
        $event = $this->eventIn($alpha, QrMode::Statique);

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.events.qr.print', $event))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Filiale Alpha', $html);
        $this->assertStringContainsString('/storage/branding/alpha.png', $html);
        $this->assertStringContainsString('#aa0000', $html);
        // Plus de marque holding « ACS Groupe » codée en dur sur la feuille.
        $this->assertStringNotContainsString('>ACS Groupe<', $html);
    }

    // ---------- Emails ----------

    public function test_email_confirmation_utilise_le_branding_de_la_filiale(): void
    {
        $beta = $this->brandedFiliale('Filiale Beta', '#00bb00', 'branding/beta.png');
        $this->brandedFiliale('Filiale Alpha', '#aa0000', 'branding/alpha.png');
        $event = $this->eventIn($beta);

        $person = Person::create(['email' => 'awa@acs.ci', 'last_name' => 'Koné', 'first_name' => 'Awa']);
        $attendance = Attendance::create([
            'event_id' => $event->id, 'person_id' => $person->id, 'reference' => 'PRS-'.Str::random(5),
            'last_name' => 'Koné', 'first_name' => 'Awa', 'phone' => '0', 'company' => 'ACS',
            'direction' => 'SI', 'position' => 'QA', 'checked_in_at' => Carbon::now(),
        ]);

        $html = (new AttendanceConfirmationMail($attendance))->render();

        $this->assertStringContainsString('Filiale Beta', $html);
        $this->assertStringContainsString('#00bb00', $html);
        $this->assertStringNotContainsString('Filiale Alpha', $html);
    }

    public function test_email_report_utilise_le_branding_de_la_filiale(): void
    {
        $alpha = $this->brandedFiliale('Filiale Alpha', '#aa0000', 'branding/alpha.png');
        $this->brandedFiliale('Filiale Beta', '#00bb00', 'branding/beta.png');
        $event = $this->eventIn($alpha);

        $reschedule = $event->reschedules()->create([
            'old_starts_at' => Carbon::now()->subDay(),
            'old_ends_at' => Carbon::now()->subDay()->addHour(),
            'new_starts_at' => Carbon::now()->addDay(),
            'new_ends_at' => Carbon::now()->addDay()->addHour(),
            'reason' => 'Salle indisponible',
        ]);
        $person = Person::create(['email' => 'awa@acs.ci', 'last_name' => 'Koné', 'first_name' => 'Awa']);

        $html = (new EventRescheduledMail($person, $reschedule, 1))->render();

        $this->assertStringContainsString('Filiale Alpha', $html);
        $this->assertStringContainsString('#aa0000', $html);
        $this->assertStringNotContainsString('Filiale Beta', $html);
    }

    public function test_email_invitation_tournant_ne_propose_aucun_lien_direct(): void
    {
        $alpha = $this->brandedFiliale('Filiale Alpha', '#aa0000', 'branding/alpha.png');
        $event = $this->eventIn($alpha, QrMode::Tournant);
        $person = Person::create(['email' => 'awa@acs.ci', 'last_name' => 'Koné', 'first_name' => 'Awa']);
        $invitation = $event->invitations()->create(['person_id' => $person->id]);

        $html = (new EventInvitationMail($invitation))->render();

        // Aucun lien d'émargement (le token tournant manquant afficherait « QR
        // expiré ») : on guide vers le scan sur place.
        $this->assertStringNotContainsString('/e/'.$event->public_slug, $html);
        $this->assertStringContainsString('scannez le qr', mb_strtolower($html));
        $this->assertStringContainsString('Filiale Alpha', $html);
        $this->assertStringContainsString('#aa0000', $html);
    }

    public function test_email_invitation_statique_ne_propose_pas_non_plus_de_lien_direct(): void
    {
        // DÉCISION PRODUIT : même en mode statique (où le lien fonctionnerait), on
        // ne diffuse pas de lien d'émargement par email (anti-fraude « scan sur
        // place » + cohérence entre les deux modes).
        $beta = $this->brandedFiliale('Filiale Beta', '#00bb00', 'branding/beta.png');
        $event = $this->eventIn($beta, QrMode::Statique);
        $person = Person::create(['email' => 'koffi@acs.ci', 'last_name' => 'Koffi', 'first_name' => 'Yao']);
        $invitation = $event->invitations()->create(['person_id' => $person->id]);

        $html = (new EventInvitationMail($invitation))->render();

        $this->assertStringNotContainsString('/e/'.$event->public_slug, $html);
        $this->assertStringContainsString('scannez le qr', mb_strtolower($html));
        $this->assertStringContainsString('Filiale Beta', $html);
    }
}
