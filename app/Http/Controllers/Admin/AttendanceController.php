<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreManualAttendanceRequest;
use App\Models\Attendance;
use App\Models\Event;
use App\Services\Attendance\AttendanceInput;
use App\Services\AttendanceService;
use App\Services\EventPresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Actions sur les présences d'un événement (côté organisateur) :
 * liste temps quasi réel (polling), export CSV, départs, saisie manuelle, signature.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendances,
        private readonly EventPresenceService $presence,
    ) {}

    /** Flux JSON pour le rafraîchissement temps quasi réel (polling). */
    public function feed(Event $event): JsonResponse
    {
        return response()->json([
            'rows' => $this->presence->rows($event),
            'stats' => $this->presence->stats($event),
        ]);
    }

    /** Export CSV (UTF-8 avec BOM, séparateur « ; » pour Excel FR). */
    public function export(Event $event): StreamedResponse
    {
        $rows = $this->presence->rows($event);
        $filename = 'presence-'.$event->public_slug.'-'.Carbon::now()->format('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8

            fputcsv($out, [
                'Nom', 'Prénom', 'Email', 'Téléphone', 'Entité/Entreprise',
                'Direction', 'Service', 'Poste', 'Heure arrivée', 'Heure départ',
                'Saisie', 'Statut',
            ], ';');

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['last_name'], $r['first_name'], $r['email'], $r['phone'], $r['company'],
                    $r['direction'], $r['service'], $r['position'], $r['time'], $r['left'] ?? '',
                    $r['manual'] ? 'Manuelle' : 'QR',
                    $r['recurrent'] ? 'Récurrent' : 'Nouveau',
                ], ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Marque un départ (définitif, informatif — n'affecte aucun KPI). */
    public function departure(Event $event, Attendance $attendance): JsonResponse
    {
        $this->attendances->markDeparture($attendance);

        return response()->json(['left' => $attendance->departed_at?->format('H:i')]);
    }

    /** Annule un départ posé par erreur. */
    public function undoDeparture(Event $event, Attendance $attendance): JsonResponse
    {
        $this->attendances->undoDeparture($attendance);

        return response()->json(['left' => null]);
    }

    /** Enregistre une présence saisie manuellement par l'organisateur. */
    public function storeManual(StoreManualAttendanceRequest $request, Event $event): JsonResponse
    {
        if (! $event->isOpenForCheckIn()) {
            return response()->json(['message' => "L'émargement n'est pas ouvert."], 422);
        }

        // Une Personne est identifiée par email ; sans email fourni, on génère une
        // clé interne synthétique pour respecter l'unicité (visiteur sans email).
        $email = $request->filled('email')
            ? (string) $request->string('email')
            : 'manuel-'.Str::random(16).'@presence.local';

        $attendance = $this->attendances->register($event, new AttendanceInput(
            email: $email,
            lastName: (string) $request->string('last_name'),
            firstName: (string) $request->string('first_name'),
            phone: $request->filled('phone') ? (string) $request->string('phone') : null,
            company: (string) $request->string('company'),
            direction: (string) $request->string('direction'),
            service: $request->filled('service') ? (string) $request->string('service') : null,
            position: (string) $request->string('position'),
            isManual: true,
            manualConfirmed: true,
            recordedBy: $request->user()->id,
        ));

        return response()->json(['reference' => $attendance->reference], 201);
    }

    /** Renvoie l'image de signature (disque privé, accès authentifié uniquement). */
    public function signature(Event $event, Attendance $attendance): StreamedResponse
    {
        abort_if($attendance->signature_path === null, 404);
        abort_unless(Storage::disk('local')->exists($attendance->signature_path), 404);

        return Storage::disk('local')->response(
            $attendance->signature_path,
            null,
            ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=3600'],
        );
    }
}
