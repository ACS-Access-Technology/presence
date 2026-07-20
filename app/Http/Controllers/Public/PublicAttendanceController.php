<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\QrMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreAttendanceRequest;
use App\Models\Event;
use App\Services\Attendance\AttendanceInput;
use App\Services\AttendanceService;
use App\Services\QrTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        if (RateLimiter::tooManyAttempts($throttleKey, 15)) {
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

    /** Enregistre la présence (idempotent, anti-chevauchement, ticket vérifié). */
    public function store(StoreAttendanceRequest $request, Event $event): JsonResponse
    {
        if (! $event->isOpenForCheckIn()) {
            return response()->json(['message' => "L'émargement n'est pas ouvert."], 422);
        }

        if (! $this->tokens->verifyScanTicket($event, (string) $request->string('ticket'))) {
            return response()->json([
                'message' => 'Votre session de scan a expiré. Rescannez le QR pour continuer.',
            ], 419);
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

        $signaturePath = $this->storeSignature($event, (string) $request->string('signature'));

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
            latitude: (float) $request->float('latitude'),
            longitude: (float) $request->float('longitude'),
            accuracy: $request->filled('accuracy') ? (float) $request->float('accuracy') : null,
        );

        $attendance = $this->attendances->register($event, $input, $overlap);

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

    /** Décode et stocke la signature PNG sur le disque privé. */
    private function storeSignature(Event $event, string $dataUri): string
    {
        $base64 = substr($dataUri, strlen('data:image/png;base64,'));
        $binary = base64_decode($base64, true);

        // La validation (starts_with) garantit le préfixe ; on protège le décodage.
        if ($binary === false) {
            abort(422, 'Signature illisible.');
        }

        $path = 'signatures/'.$event->id.'/'.Str::uuid()->toString().'.png';
        Storage::disk('local')->put($path, $binary);

        return $path;
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
