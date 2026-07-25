<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exports\AttendanceExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreManualAttendanceRequest;
use App\Models\Attendance;
use App\Models\Event;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\SignatureStorage;
use App\Services\AttendanceService;
use App\Services\EventPresenceService;
use App\Support\SpreadsheetSafety;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    /**
     * Grille de badges imprimables (A4, découpe manuelle) pour les présents.
     * Impression via le navigateur — aucun matériel dédié requis.
     */
    public function badges(Event $event): View
    {
        return view('admin.events.badges-print', [
            'event' => $event,
            'rows' => $this->presence->rows($event),
        ]);
    }

    /** Export CSV (UTF-8 avec BOM, séparateur « ; » pour Excel FR). */
    public function export(Request $request, Event $event): StreamedResponse
    {
        $rows = $this->filteredRows($request, $event);
        $filename = $this->exportFilename($event, 'csv');

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8

            fputcsv($out, [
                'Nom', 'Prénom', 'Email', 'Téléphone', 'Entité/Entreprise',
                'Direction', 'Service', 'Poste', 'Heure arrivée', 'Heure départ',
                'Saisie', 'Statut',
            ], ';');

            foreach ($rows as $r) {
                fputcsv($out, SpreadsheetSafety::sanitizeRow([
                    $r['last_name'], $r['first_name'], $r['email'], $r['phone'], $r['company'],
                    $r['direction'], $r['service'], $r['position'], $r['time'], $r['left'] ?? '',
                    $r['manual'] ? 'Manuelle' : 'QR',
                    $r['recurrent'] ? 'Récurrent' : 'Nouveau',
                ]), ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Export XLSX — mêmes lignes/filtres que l'export CSV, signatures incluses. */
    public function exportXlsx(Request $request, Event $event): BinaryFileResponse
    {
        $rows = $this->withSignaturePaths($this->filteredRows($request, $event));

        return Excel::download(new AttendanceExport($rows), $this->exportFilename($event, 'xlsx'));
    }

    /** Export PDF — mêmes lignes/filtres que l'export CSV, signatures incluses. */
    public function exportPdf(Request $request, Event $event): Response
    {
        $rows = $this->withSignatureDataUris($this->filteredRows($request, $event));

        return Pdf::loadView('admin.events.pdf.attendances', ['event' => $event, 'rows' => $rows])
            ->setPaper('a4', 'landscape')
            ->download($this->exportFilename($event, 'pdf'));
    }

    /**
     * Ajoute le chemin disque brut de la signature (usage export uniquement —
     * jamais exposé via le flux JSON `feed`, qui reste sur signature_url/route).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withSignaturePaths(array $rows): array
    {
        $paths = Attendance::query()
            ->whereIn('id', array_column($rows, 'id'))
            ->pluck('signature_path', 'id');

        return array_map(function (array $r) use ($paths): array {
            $r['signature_path'] = $paths[$r['id']] ?? null;

            return $r;
        }, $rows);
    }

    /**
     * Comme {@see withSignaturePaths}, mais encode directement l'image en data URI
     * (dompdf ne peut pas passer par une route authentifiée pour charger un <img>).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withSignatureDataUris(array $rows): array
    {
        return array_map(function (array $r): array {
            $path = $r['signature_path'] ?? null;
            $r['signature_data_uri'] = ($path !== null && Storage::disk('local')->exists($path))
                ? 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($path))
                : null;

            return $r;
        }, $this->withSignaturePaths($rows));
    }

    /**
     * Applique les mêmes filtres que la liste à l'écran (chip statut + recherche
     * texte) avant export, pour que le fichier téléchargé corresponde à ce que
     * l'organisateur voit à l'instant.
     *
     * @return list<array<string, mixed>>
     */
    private function filteredRows(Request $request, Event $event): array
    {
        $rows = $this->presence->rows($event);

        $chip = $request->string('chip', 'all')->toString();
        if ($chip === 'new') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => ! $r['recurrent']));
        } elseif ($chip === 'rec') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => $r['recurrent']));
        }

        $q = mb_strtolower(trim((string) $request->string('q', '')));
        if ($q !== '') {
            $rows = array_values(array_filter($rows, function (array $r) use ($q): bool {
                $haystack = mb_strtolower($r['name'].' '.($r['company'] ?? '').' '.($r['direction'] ?? '').' '.($r['email'] ?? ''));

                return str_contains($haystack, $q);
            }));
        }

        return $rows;
    }

    private function exportFilename(Event $event, string $extension): string
    {
        return 'presence-'.$event->public_slug.'-'.Carbon::now()->format('Ymd-Hi').'.'.$extension;
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
        $hasEmail = $request->filled('email');
        $email = $hasEmail
            ? (string) $request->string('email')
            : 'manuel-'.Str::random(16).'@presence.local';

        // Anti-chevauchement : même garde que le flux public. Un organisateur ne doit
        // pas pouvoir inscrire manuellement une personne déjà active sur un autre
        // événement sans confirmer explicitement son départ, sinon on crée une
        // présence simultanée à deux activités. Seul un email réel peut correspondre
        // à une fiche existante (l'email synthétique est unique à chaque saisie).
        // Choix documenté : on réutilise le même protocole que le public
        // (`confirm_departure` + réponse 409 { overlap }) pour que le front admin
        // affiche la même demande de confirmation avant de renvoyer la saisie.
        $person = $hasEmail ? $this->attendances->findPersonByEmail($email) : null;
        $overlap = $person !== null ? $this->attendances->activeOverlap($person, $event) : null;

        if ($overlap !== null && ! $request->boolean('confirm_departure')) {
            // Pré-contrôle AVANT d'écrire la signature (pas d'orphelin sur disque).
            return response()->json([
                'overlap' => [
                    'event_title' => $overlap->event->title,
                    'when' => $this->formatWindow($overlap->event),
                    'location' => $overlap->event->location,
                ],
            ], 409);
        }

        $signaturePath = SignatureStorage::store($event->id, (string) $request->string('signature'));

        // register() est la garde autoritaire sous verrou : en cas de course, il
        // clôture automatiquement la présence conflictuelle (une seule active).
        $attendance = $this->attendances->register($event, new AttendanceInput(
            email: $email,
            lastName: (string) $request->string('last_name'),
            firstName: (string) $request->string('first_name'),
            phone: $request->filled('phone') ? (string) $request->string('phone') : null,
            company: (string) $request->string('company'),
            direction: (string) $request->string('direction'),
            service: $request->filled('service') ? (string) $request->string('service') : null,
            position: (string) $request->string('position'),
            signaturePath: $signaturePath,
            isManual: true,
            manualConfirmed: true,
            recordedBy: $request->user()->id,
        ));

        // Soumission répétée (idempotence) : la présence existait déjà → on retire
        // le fichier signature fraîchement écrit pour ne pas laisser d'orphelin.
        if (! $attendance->wasRecentlyCreated) {
            Storage::disk('local')->delete($signaturePath);
        }

        return response()->json(['reference' => $attendance->reference], 201);
    }

    /**
     * Fenêtre horaire lisible d'un événement, ex. « aujourd'hui · 13:30 → 15:30 ».
     *
     * Dupliqué (volontairement) du contrôleur public : petit formateur pur, isolé
     * ici pour ne pas coupler ce contrôleur admin au contrôleur public ni toucher
     * au modèle Event (surface partagée avec d'autres travaux en cours).
     */
    private function formatWindow(Event $event): string
    {
        $day = $event->starts_at->isToday()
            ? "aujourd'hui"
            : $event->starts_at->translatedFormat('j M');

        return $day.' · '.$event->starts_at->format('H:i').' → '.$event->ends_at->format('H:i');
    }

    /** Renvoie l'image de signature (disque privé, accès authentifié uniquement). */
    public function signature(Event $event, Attendance $attendance): StreamedResponse
    {
        abort_if($attendance->signature_path === null, 404);
        abort_unless(Storage::disk('local')->exists($attendance->signature_path), 404);

        return Storage::disk('local')->response(
            $attendance->signature_path,
            null,
            [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
