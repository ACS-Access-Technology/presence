<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\EventLifecycleController;
use App\Http\Controllers\Admin\EventQrController;
use App\Http\Controllers\Admin\EventTypeController;
use App\Http\Controllers\Admin\ParticipantController;
use App\Http\Controllers\Admin\PersonSearchController;
use App\Http\Controllers\Admin\PortfolioController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Auth\LoginController;
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
    Route::post('/{event:public_slug}/recognize', [PublicAttendanceController::class, 'recognize'])->name('recognize');
    Route::post('/{event:public_slug}', [PublicAttendanceController::class, 'store'])->name('store');
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
| Les Paramètres (types, comptes, branding) seront réservés à `role:admin`.
*/
Route::middleware('auth')->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Annuaire des participants (recherche, historique, stats).
    Route::get('/participants', [ParticipantController::class, 'index'])->name('participants.index');
    Route::get('/participants/{person}', [ParticipantController::class, 'show'])->name('participants.show');

    // Portfolio des activités documentées.
    Route::get('/portfolio', [PortfolioController::class, 'index'])->name('portfolio');

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
            Route::post('/manual', [AttendanceController::class, 'storeManual'])->name('manual');
            Route::post('/{attendance}/departure', [AttendanceController::class, 'departure'])->name('departure');
            Route::post('/{attendance}/undo-departure', [AttendanceController::class, 'undoDeparture'])->name('undo-departure');
            Route::get('/{attendance}/signature', [AttendanceController::class, 'signature'])->name('signature');
        });

    // Cycle de vie d'un événement : annulation (réversible) et report.
    Route::post('/events/{event}/cancel', [EventLifecycleController::class, 'cancel'])->name('events.cancel');
    Route::post('/events/{event}/uncancel', [EventLifecycleController::class, 'uncancel'])->name('events.uncancel');
    Route::post('/events/{event}/reschedule', [EventLifecycleController::class, 'reschedule'])->name('events.reschedule');

    // Compte-rendu d'un événement (texte + documents + photos).
    Route::prefix('events/{event}/report')->name('events.report.')
        ->scopeBindings()->group(function (): void {
            Route::post('/', [ReportController::class, 'saveText'])->name('save');
            Route::post('/documents', [ReportController::class, 'uploadDocuments'])->name('documents.store');
            Route::delete('/documents/{document}', [ReportController::class, 'destroyDocument'])->name('documents.destroy');
            Route::post('/photos', [ReportController::class, 'uploadPhotos'])->name('photos.store');
            Route::delete('/photos/{photo}', [ReportController::class, 'destroyPhoto'])->name('photos.destroy');
        });

    // Diffusion du QR (projection tournante / impression statique / polling token).
    Route::get('/events/{event}/projection', [EventQrController::class, 'projection'])->name('events.projection');
    Route::get('/events/{event}/qr/current', [EventQrController::class, 'current'])->name('events.qr.current');
    Route::get('/events/{event}/qr/print', [EventQrController::class, 'print'])->name('events.qr.print');

    // Paramètres (administrateurs uniquement).
    Route::middleware('role:admin')->prefix('settings')->name('settings.')->group(function (): void {
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
});
