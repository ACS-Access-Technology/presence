<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\Event;
use App\Models\Partner;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Branding EFFECTIF prêt à l'affichage, résolu depuis la FILIALE concernée.
 *
 * Point unique de résolution du branding pour toutes les surfaces publiques et
 * les emails. {@see Setting::branding()} renvoie des valeurs BRUTES (chemin de
 * logo dans le disque `public`, couleur nullable) ; ce presenter les transforme
 * en valeurs directement consommables par une vue ou un email :
 *  - `logoUrl` : URL absolue du logo (logo de la filiale, ou repli logo holding) ;
 *  - `accentColor` : couleur d'accent VALIDÉE (`#rrggbb`) ou `null` ;
 *  - `orgName` : nom affiché de la filiale (repli holding « ACS Groupe »).
 *
 * IMPORTANT — indépendance vis-à-vis de la session : la résolution part TOUJOURS
 * de `filiale_id` (via {@see forEvent()} / {@see forFiliale()}), jamais du contexte
 * de scoping courant ({@see FilialeScoping}). Un email envoyé par un job en file
 * ou une page publique consultée par un visiteur anonyme n'a AUCUNE session admin
 * active ; se fier au contexte SuperAdmin qui aurait déclenché l'action donnerait
 * le mauvais branding (bug identité visuelle P1).
 *
 * Rencontre partenaire : quand l'événement est organisé pour le compte d'un
 * partenaire, SON identité (nom, logo, couleur) remplace intégralement celle de
 * la filiale (marque blanche, décision produit du 2026-10-21). `onBehalfOf`
 * garde alors le nom du partenaire pour la seule mention RGPD, qui doit rester
 * vraie : c'est ACS Groupe qui collecte et conserve les données.
 */
final class Branding
{
    /** Logo holding de repli, servi quand la filiale n'a pas de logo propre. */
    public const string FALLBACK_LOGO = 'assets/logo-acs-groupe.png';

    /** Bleu marine ACS, accent de repli lorsqu'aucune couleur n'est configurée. */
    public const string DEFAULT_ACCENT = '#1e2a78';

    private function __construct(
        public readonly string $orgName,
        public readonly string $logoUrl,
        public readonly ?string $accentColor,
        public readonly bool $hasCustomLogo,
        public readonly ?string $onBehalfOf = null,
        public readonly ?string $logoPublicPath = null,
    ) {}

    /**
     * Branding d'un événement : celui de son partenaire s'il en a un, sinon celui
     * de sa filiale.
     */
    public static function forEvent(Event $event): self
    {
        $partner = $event->partner_id !== null ? $event->partner : null;

        return $partner instanceof Partner
            ? self::forPartner($partner)
            : self::forFiliale($event->filiale_id);
    }

    /** Identité visuelle d'un partenaire (marque blanche). */
    public static function forPartner(Partner $partner): self
    {
        return new self(
            orgName: $partner->name,
            logoUrl: Storage::disk('public')->url($partner->logo_path),
            accentColor: self::sanitizeHex($partner->primary_color),
            hasCustomLogo: true,
            onBehalfOf: $partner->name,
            logoPublicPath: $partner->logo_path,
        );
    }

    /**
     * Logo encodé en data URI pour dompdf (qui ne charge pas d'URL distante en
     * configuration par défaut). `null` si aucun logo propre n'est lisible.
     */
    public function logoDataUri(): ?string
    {
        if ($this->logoPublicPath === null || ! Storage::disk('public')->exists($this->logoPublicPath)) {
            return null;
        }

        // Raster uniquement : un ancien logo SVG (téléversé avant l'interdiction)
        // ne doit jamais être injecté dans dompdf.
        $mime = Storage::disk('public')->mimeType($this->logoPublicPath);
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk('public')->get($this->logoPublicPath));
    }

    /** Branding effectif d'une filiale (défaut : holding si `null`). */
    public static function forFiliale(?int $filialeId): self
    {
        $branding = Setting::branding($filialeId);

        $logoPath = $branding['logo_path'];
        $hasCustomLogo = $logoPath !== null && $logoPath !== '';

        return new self(
            orgName: $branding['org_name'],
            logoUrl: $hasCustomLogo
                ? Storage::disk('public')->url($logoPath)
                : asset(self::FALLBACK_LOGO),
            accentColor: self::sanitizeHex($branding['accent_color']),
            hasCustomLogo: $hasCustomLogo,
            logoPublicPath: $hasCustomLogo ? $logoPath : null,
        );
    }

    /**
     * Couleur d'accent avec repli concret (jamais `null`). Utile là où une couleur
     * est obligatoire (fond d'en-tête d'email, `theme-color`), contrairement aux
     * pages HTML où l'absence signifie « garder les tokens CSS par défaut ».
     */
    public function accentColorOrDefault(): string
    {
        return $this->accentColor ?? self::DEFAULT_ACCENT;
    }

    /**
     * N'accepte qu'un hex `#rrggbb` strict. Défense en profondeur : la valeur est
     * injectée dans du CSS/HTML inline ; même si le champ est déjà validé à la
     * saisie ({@see SettingsController}), on refuse ici
     * toute valeur non conforme (donnée altérée en base) plutôt que de l'émettre.
     */
    private static function sanitizeHex(?string $value): ?string
    {
        if ($value !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
            return $value;
        }

        return null;
    }
}
