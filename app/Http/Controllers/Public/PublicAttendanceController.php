<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\QrMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreAttendanceRequest;
use App\Models\Event;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\SignatureStorage;
use App\Services\AttendanceService;
use App\Services\QrTokenService;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Page publique d'émargement (sans compte), mobile-first.
 *
 * Flux : scan → show() valide fenêtre + token tournant, émet un ticket de scan →
 * le visiteur remplit le formulaire → recognize() reconnaît l'email et détecte un
 * éventuel chevauchement → store() valide le ticket et enregistre la présence.
 */
class PublicAttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendances,
        private readonly QrTokenService $tokens,
    ) {}

    /** Affiche la page d'émargement (ou un écran d'erreur adapté). */
    public function show(Request $request, Event $event): View
    {
        if ($event->isCancelled()) {
            return view('public.closed', ['event' => $event, 'reason' => 'cancelled']);
        }

        if (! $event->isOpenForCheckIn()) {
            $reason = Carbon::now()->lessThan($event->starts_at) ? 'not_started' : 'ended';

            return view('public.closed', ['event' => $event, 'reason' => $reason]);
        }

        // Mode tournant : le token scanné doit être frais (fenêtre courante/précédente).
        if ($event->qr_mode === QrMode::Tournant) {
            $token = (string) $request->query('t', '');

            if ($token === '' || ! $this->tokens->verifyToken($event, $token)) {
                return view('public.qr-invalid', ['event' => $event]);
            }
        }

        return view('public.attendance', [
            'event' => $event,
            'ticket' => $this->tokens->issueScanTicket($event),
        ]);
    }

    /**
     * Manifeste PWA propre à CET événement (start_url pointe sur sa propre page) :
     * utile pour épingler un poste d'accueil/tablette dédié sur l'écran d'accueil
     * sans chrome navigateur, sans app native.
     */
    public function manifest(Event $event): JsonResponse
    {
        // Branding résolu depuis la filiale de l'événement (jamais le logo holding
        // en dur) : un poste d'accueil épinglé affiche l'identité de LA filiale.
        $branding = Branding::forEvent($event);

        return response()->json([
            'name' => $event->title.' — '.$branding->orgName,
            'short_name' => 'Presence',
            'start_url' => route('public.attendance.show', ['event' => $event->public_slug]),
            'scope' => route('public.attendance.show', ['event' => $event->public_slug]),
            'display' => 'standalone',
            'background_color' => '#eef0f4',
            'theme_color' => $branding->accentColorOrDefault(),
            'lang' => 'fr',
            'icons' => [
                // `sizes: any` : un logo de filiale a des dimensions inconnues ;
                // déclarer une taille fixe (celle du logo holding) mentirait au
                // navigateur. `any` laisse le PWA redimensionner sans contrainte.
                ['src' => $branding->logoUrl, 'sizes' => 'any', 'type' => 'image/png', 'purpose' => 'any'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    /**
     * Reconnaissance d'un visiteur par email + détection de chevauchement.
     *
     * Protégé par le ticket de scan (émis uniquement par show(), donc requiert
     * d'avoir réellement chargé une page d'émargement ouverte) + limitation de
     * débit, pour empêcher l'énumération d'adresses email et la fuite de PII
     * (téléphone, direction, service, poste) du personnel.
     */
    public function recognize(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'ticket' => ['required', 'string'],
        ]);

        if (! $event->isOpenForCheckIn()) {
            return response()->json(['message' => "L'émargement n'est pas ouvert."], 422);
        }

        if (! $this->tokens->verifyScanTicket($event, $validated['ticket'])) {
            return response()->json([
                'message' => 'Votre session de scan a expiré. Rescannez le QR pour continuer.',
            ], 419);
        }

        $throttleKey = 'attendance-recognize:'.$event->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json(['message' => 'Trop de tentatives. Réessayez dans une minute.'], 429);
        }
        RateLimiter::hit($throttleKey, 60);

        $person = $this->attendances->findPersonByEmail($validated['email']);
        $overlap = $person !== null ? $this->attendances->activeOverlap($person, $event) : null;

        return response()->json([
            'known' => $person !== null,
            'person' => $person === null ? null : [
                'first_name' => $person->first_name,
                'last_name' => $person->last_name,
                'phone' => $person->phone,
                'company' => $person->company,
                'direction' => $person->direction,
                'service' => $person->service,
                'position' => $person->position,
            ],
            'overlap' => $overlap === null ? null : [
                'event_title' => $overlap->event->title,
                'when' => $this->formatWindow($overlap->event),
                'location' => $overlap->event->location,
            ],
        ]);
    }

    /**
     * Enregistre la présence (idempotent, anti-chevauchement, ticket vérifié).
     *
     * Sorties possibles côté client (`participant.js` doit toutes les gérer) :
     * - 200 : présence enregistrée (ou déjà existante — idempotent).
     * - 403 : hors du périmètre anti-fraude configuré sur l'événement.
     * - 409 : chevauchement détecté, en attente de `confirm_departure`.
     * - 419 : ticket de scan absent/expiré/déjà trop utilisé — rescanner le QR.
     * - 422 : validation FormRequest (champs requis) ou fenêtre d'émargement fermée.
     * - 429 : trop de tentatives (throttle IP+événement).
     */
    public function store(StoreAttendanceRequest $request, Event $event): JsonResponse
    {
        if (! $event->isOpenForCheckIn()) {
            return response()->json(['message' => "L'émargement n'est pas ouvert."], 422);
        }

        // Limite le débit par IP+événement : un ticket seul ne suffit pas à brider
        // un flood (il n'est lié à personne), donc on borne aussi la fréquence.
        $throttleKey = 'attendance-store:'.$event->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            return response()->json(['message' => 'Trop de tentatives. Réessayez dans une minute.'], 429);
        }
        RateLimiter::hit($throttleKey, 60);

        $ticket = (string) $request->string('ticket');
        if (! $this->tokens->verifyScanTicket($event, $ticket)) {
            return response()->json([
                'message' => 'Votre session de scan a expiré. Rescannez le QR pour continuer.',
            ], 419);
        }

        // Un ticket de scan n'est pas lié à un acteur : sans ceci, il reste
        // rejouable indéfiniment pendant ses 5 minutes de validité et permettrait
        // à lui seul un flood de présences. Marge de 5 pour tolérer les retentatives
        // réseau légitimes (formulaire soumis deux fois par erreur).
        $ticketUsageKey = 'attendance-store-ticket:'.hash('sha256', $ticket);
        if (RateLimiter::tooManyAttempts($ticketUsageKey, 5)) {
            return response()->json([
                'message' => 'Votre session de scan a expiré. Rescannez le QR pour continuer.',
            ], 419);
        }
        RateLimiter::hit($ticketUsageKey, QrTokenService::SCAN_TICKET_TTL);

        // Périmètre anti-fraude (facultatif, configuré par l'organisateur) : vérifié
        // SERVEUR à partir de la position transmise (jamais confiance au client seul,
        // mais sans périmètre configuré, isWithinGeofence() est toujours vraie).
        $latitude = (float) $request->float('latitude');
        $longitude = (float) $request->float('longitude');
        if (! $event->isWithinGeofence($latitude, $longitude)) {
            return response()->json([
                'message' => "Vous semblez trop loin du lieu de l'événement pour émarger. Rapprochez-vous et réessayez.",
            ], 403);
        }

        // Recalcul serveur du chevauchement (ne jamais faire confiance au client).
        $person = $this->attendances->findPersonByEmail((string) $request->string('email'));
        $overlap = $person !== null ? $this->attendances->activeOverlap($person, $event) : null;

        if ($overlap !== null && ! $request->boolean('confirm_departure')) {
            return response()->json([
                'overlap' => [
                    'event_title' => $overlap->event->title,
                    'when' => $this->formatWindow($overlap->event),
                    'location' => $overlap->event->location,
                ],
            ], 409);
        }

        $signaturePath = SignatureStorage::store($event->id, (string) $request->string('signature'));

        $input = new AttendanceInput(
            email: (string) $request->string('email'),
            lastName: (string) $request->string('last_name'),
            firstName: (string) $request->string('first_name'),
            phone: (string) $request->string('phone'),
            company: (string) $request->string('company'),
            direction: (string) $request->string('direction'),
            service: $request->filled('service') ? (string) $request->string('service') : null,
            position: (string) $request->string('position'),
            signaturePath: $signaturePath,
            latitude: $latitude,
            longitude: $longitude,
            accuracy: $request->filled('accuracy') ? (float) $request->float('accuracy') : null,
        );

        // register() est la garde autoritaire sous verrou : si un chevauchement est
        // apparu entre le pré-contrôle ci-dessus (non verrouillé) et l'insertion, il
        // clôture automatiquement la présence précédente (une seule présence active à
        // la fois). Le 409 ci-dessus couvre le cas nominal (demande de confirmation).
        $attendance = $this->attendances->register($event, $input);

        // Soumission répétée (idempotence) : la présence existait déjà → on retire
        // le fichier signature fraîchement écrit pour ne pas laisser d'orphelin.
        if (! $attendance->wasRecentlyCreated) {
            Storage::disk('local')->delete($signaturePath);
        }

        return response()->json([
            'reference' => $attendance->reference,
            'first_name' => $attendance->first_name,
            'event_title' => $event->title,
            'checked_in_at' => $attendance->checked_in_at->translatedFormat('j M Y · H:i'),
            'departed_previous' => $overlap?->event->title,
        ]);
    }

    /** Fenêtre horaire lisible d'un événement, ex. « aujourd'hui · 13:30 → 15:30 ». */
    private function formatWindow(Event $event): string
    {
        $day = $event->starts_at->isToday()
            ? "aujourd'hui"
            : $event->starts_at->translatedFormat('j M');

        return $day.' · '.$event->starts_at->format('H:i').' → '.$event->ends_at->format('H:i');
    }
}
