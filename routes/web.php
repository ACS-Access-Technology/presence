<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\EventLifecycleController;
use App\Http\Controllers\Admin\EventQrController;
use App\Http\Controllers\Admin\EventTransferController;
use App\Http\Controllers\Admin\EventTypeController;
use App\Http\Controllers\Admin\FilialeContextController;
use App\Http\Controllers\Admin\FilialeController;
use App\Http\Controllers\Admin\ParticipantController;
use App\Http\Controllers\Admin\PersonSearchController;
use App\Http\Controllers\Admin\PortfolioController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StatisticsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Public\FeedbackController;
use App\Http\Controllers\Public\PublicAttendanceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes publiques
|--------------------------------------------------------------------------
| La page publique d'émargement (/e/{slug}) sera ajoutée avec le contrôleur
| public une fois le socle validé. Racine → connexion (ou dashboard si connecté).
*/
Route::get('/', static fn () => auth()->check()
    ? redirect()->route('admin.dashboard')
    : redirect()->route('login'));

/*
|--------------------------------------------------------------------------
| Page publique d'émargement (sans compte)
|--------------------------------------------------------------------------
| /e/{slug} : scan → formulaire ; recognize (email) ; store (soumission).
*/
Route::prefix('e')->name('public.attendance.')->group(function (): void {
    Route::get('/{event:public_slug}', [PublicAttendanceController::class, 'show'])->name('show');
    Route::get('/{event:public_slug}/manifest.json', [PublicAttendanceController::class, 'manifest'])->name('manifest');
    Route::post('/{event:public_slug}/recognize', [PublicAttendanceController::class, 'recognize'])->name('recognize');
    Route::post('/{event:public_slug}', [PublicAttendanceController::class, 'store'])->name('store');
});

/*
|--------------------------------------------------------------------------
| Avis post-événement (sans compte)
|--------------------------------------------------------------------------
| /avis/{reference} : lien envoyé dans l'email de confirmation de présence.
*/
Route::prefix('avis')->name('public.feedback.')->group(function (): void {
    // `throttle` (middleware Laravel natif) tourne AVANT `SubstituteBindings` dans la
    // liste de priorité du kernel : la limite par IP s'applique donc même sur une
    // référence inexistante (404 côté binding), contrairement à un throttle posé dans
    // le corps du contrôleur qui ne s'exécuterait jamais dans ce cas — ce qui annulait
    // la protection anti-énumération pour tout accès sur une référence invalide.
    Route::get('/{attendance:reference}', [FeedbackController::class, 'show'])->name('show')->middleware('throttle:30,1');
    Route::post('/{attendance:reference}', [FeedbackController::class, 'store'])->name('store')->middleware('throttle:10,1');
});

/*
|--------------------------------------------------------------------------
| Authentification (comptes internes ACS Groupe)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function (): void {
    Route::get('/connexion', [LoginController::class, 'show'])->name('login');
    Route::post('/connexion', [LoginController::class, 'store']);
});

Route::post('/deconnexion', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Tableau de bord (authentifié)
|--------------------------------------------------------------------------
| Les Paramètres (types, comptes, branding) sont réservés aux administrateurs
| (AdminFiliale, scopé sa filiale ; SuperAdmin, global).
|
| `filiale.scope` active le cloisonnement par filiale pour TOUTES les routes
| admin (arbitrage Q-ME-3). C'est le seul point d'activation : le cron et la
| page publique `/e/{slug}`, hors de ce groupe, ne sont jamais scopés (RME-2).
*/
Route::middleware(['auth', 'session.active', 'filiale.scope'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Compte personnel (self-service, tout rôle) : changement de mot de passe.
    Route::get('/mon-compte', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/mon-compte/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // Annuaire des participants (recherche, historique, stats).
    Route::get('/participants', [ParticipantController::class, 'index'])->name('participants.index');
    Route::get('/participants/{person}', [ParticipantController::class, 'show'])->name('participants.show');

    // Portfolio des activités documentées.
    Route::get('/portfolio', [PortfolioController::class, 'index'])->name('portfolio');

    // Statistiques globales (toutes activités confondues).
    Route::get('/statistiques', [StatisticsController::class, 'index'])->name('statistics');

    // Événements : liste + création + détail (liste de présence, stats).
    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::get('/events/create', [EventController::class, 'create'])->name('events.create');
    Route::post('/events', [EventController::class, 'store'])->name('events.store');
    Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
    Route::patch('/events/{event}', [EventController::class, 'update'])->name('events.update');

    // Recherche du référentiel « Personnel ACS Groupe » (combobox d'invitation).
    Route::get('/people/search', [PersonSearchController::class, 'search'])->name('people.search');

    // Présences d'un événement (liaisons imbriquées vérifiées).
    Route::prefix('events/{event}/attendances')->name('events.attendances.')
        ->scopeBindings()->group(function (): void {
            Route::get('/feed', [AttendanceController::class, 'feed'])->name('feed');
            Route::get('/export', [AttendanceController::class, 'export'])->name('export');
            Route::get('/export.xlsx', [AttendanceController::class, 'exportXlsx'])->name('export.xlsx');
            Route::get('/export.pdf', [AttendanceController::class, 'exportPdf'])->name('export.pdf');
            Route::get('/badges', [AttendanceController::class, 'badges'])->name('badges');
            Route::post('/manual', [AttendanceController::class, 'storeManual'])->name('manual');
            Route::post('/{attendance}/departure', [AttendanceController::class, 'departure'])->name('departure');
            Route::post('/{attendance}/undo-departure', [AttendanceController::class, 'undoDeparture'])->name('undo-departure');
            Route::get('/{attendance}/signature', [AttendanceController::class, 'signature'])->name('signature');
        });

    // Cycle de vie d'un événement : annulation (réversible), report et mode QR.
    Route::post('/events/{event}/cancel', [EventLifecycleController::class, 'cancel'])->name('events.cancel');
    Route::post('/events/{event}/uncancel', [EventLifecycleController::class, 'uncancel'])->name('events.uncancel');
    Route::post('/events/{event}/reschedule', [EventLifecycleController::class, 'reschedule'])->name('events.reschedule');
    Route::patch('/events/{event}/qr-mode', [EventLifecycleController::class, 'changeQrMode'])->name('events.qr-mode');

    // Compte-rendu d'un événement (texte + documents + photos).
    Route::prefix('events/{event}/report')->name('events.report.')
        ->scopeBindings()->group(function (): void {
            Route::post('/', [ReportController::class, 'saveText'])->name('save');
            Route::post('/documents', [ReportController::class, 'uploadDocuments'])->name('documents.store');
            // Service des médias depuis le disque PRIVÉ (jamais d'URL publique devinable).
            Route::get('/documents/{document}', [ReportController::class, 'showDocument'])->name('documents.show');
            Route::delete('/documents/{document}', [ReportController::class, 'destroyDocument'])->name('documents.destroy');
            Route::post('/photos', [ReportController::class, 'uploadPhotos'])->name('photos.store');
            Route::get('/photos/{photo}', [ReportController::class, 'showPhoto'])->name('photos.show');
            Route::delete('/photos/{photo}', [ReportController::class, 'destroyPhoto'])->name('photos.destroy');
        });

    // Diffusion du QR (projection tournante / impression statique / polling token).
    Route::get('/events/{event}/projection', [EventQrController::class, 'projection'])->name('events.projection');
    Route::get('/events/{event}/qr/current', [EventQrController::class, 'current'])->name('events.qr.current');
    Route::get('/events/{event}/qr/print', [EventQrController::class, 'print'])->name('events.qr.print');

    // Paramètres (administrateurs : AdminFiliale scopé à sa filiale, SuperAdmin
    // global/selon le contexte). Le cloisonnement par filiale des types, comptes
    // et branding est désormais implémenté et testé (Lots D/F) :
    //   - EventType : global scope de filiale → binding {type} 404 cross-filiale ;
    //   - User : UserPolicy (manage/reassign) + scoping explicite → 403 IDOR,
    //            rôle super_admin non attribuable (anti-escalade).
    Route::middleware('role:admin_filiale,super_admin')->prefix('settings')->name('settings.')->group(function (): void {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::post('/branding', [SettingsController::class, 'saveBranding'])->name('branding');

        Route::post('/types', [EventTypeController::class, 'store'])->name('types.store');
        Route::patch('/types/{type}', [EventTypeController::class, 'update'])->name('types.update');
        Route::delete('/types/{type}', [EventTypeController::class, 'destroy'])->name('types.destroy');

        Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::patch('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::post('/accounts/{account}/reset-password', [AccountController::class, 'resetPassword'])->name('accounts.reset');
        Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
    });

    // Surfaces réservées au SuperAdmin : gestion des filiales de la holding,
    // réassignation d'un compte entre filiales, sélecteur de contexte filiale.
    Route::middleware('role:super_admin')->group(function (): void {
        Route::get('/filiales', [FilialeController::class, 'index'])->name('filiales.index');
        Route::post('/filiales', [FilialeController::class, 'store'])->name('filiales.store');
        Route::patch('/filiales/{filiale}', [FilialeController::class, 'update'])->name('filiales.update');
        Route::patch('/filiales/{filiale}/toggle', [FilialeController::class, 'toggle'])->name('filiales.toggle');

        Route::post('/settings/accounts/{account}/reassign', [AccountController::class, 'reassign'])->name('settings.accounts.reassign');

        // Transfert d'un événement (ou de sa série) vers une autre filiale (T-ME-14).
        Route::post('/events/{event}/transfer', [EventTransferController::class, 'transfer'])->name('events.transfer');

        Route::post('/contexte-filiale', [FilialeContextController::class, 'update'])->name('filiale-context.update');
    });
});
