<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Event;
use App\Support\Branding;
use App\Support\FilialeScoping;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Contexte runtime du cloisonnement par filiale (arbitrage Q-ME-3).
        // Singleton : une seule décision de scoping par cycle de requête.
        $this->app->singleton(FilialeScoping::class);
    }

    public function boot(): void
    {
        // Compat MySQL < 8 / anciennes configs InnoDB : limite la longueur d'index
        // par défaut (191 = 767 octets en utf8mb4) pour éviter « key too long ».
        // MySQL local MAMP = 5.7 ; cible prod probable MySQL 8 / MariaDB 10+.
        Schema::defaultStringLength(191);

        // Rigueur en développement : lève une exception sur accès à un attribut
        // non chargé, une assignation de masse hors $fillable, etc.
        Model::shouldBeStrict($this->app->isLocal());

        // Injecte le branding EFFECTIF (résolu depuis la filiale de l'événement)
        // dans les surfaces publiques et les vues QR. Résolu ici, une seule fois
        // par vue, à partir du `$event` déjà présent dans les données de la vue —
        // jamais depuis le contexte de session (invalide pour un visiteur anonyme).
        // Repli holding si aucun événement n'est fourni.
        // Cible les vues ENFANTS (pas `layouts.public`) : la donnée partagée sur
        // la vue rendue est disponible dans ses sections ET propagée au layout via
        // @extends. Injecter seulement sur le layout laisserait `$branding` absent
        // des sections enfant (footer, etc.).
        ViewFacade::composer(
            [
                'public.attendance', 'public.closed', 'public.qr-invalid', 'public.feedback',
                'admin.events.projection', 'admin.events.qr-print',
            ],
            function (View $view): void {
                $event = $view->getData()['event'] ?? null;

                $view->with('branding', $event instanceof Event
                    ? Branding::forEvent($event)
                    : Branding::forFiliale(null));
            },
        );
    }
}
