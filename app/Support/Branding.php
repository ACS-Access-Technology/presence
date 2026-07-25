<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\Event;
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
    ) {}

    /** Branding de la filiale à laquelle appartient l'événement. */
    public static function forEvent(Event $event): self
    {
        return self::forFiliale($event->filiale_id);
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
