<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Support\Branding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Feuille d'émargement PDF d'un événement — source unique pour le
 * téléchargement admin et pour l'envoi automatique au partenaire à la clôture.
 *
 * L'en-tête reprend le branding effectif de l'événement ({@see Branding::forEvent()}) :
 * logo et couleur du partenaire pour une rencontre partenaire, sinon ceux de la
 * filiale. Les images (logo, signatures) sont encodées en data URI : dompdf ne
 * peut charger ni une route authentifiée ni, en configuration par défaut, une URL
 * distante.
 */
final class AttendanceSheetPdf
{
    public function __construct(private readonly EventPresenceService $presence) {}

    /** PDF (octets bruts) de TOUTES les présences de l'événement. */
    public function renderAll(Event $event): string
    {
        return $this->render($event, $this->presence->rows($event));
    }

    /**
     * PDF (octets bruts) des lignes fournies (déjà filtrées par l'appelant).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function render(Event $event, array $rows): string
    {
        $branding = Branding::forEvent($event);

        return Pdf::loadView('admin.events.pdf.attendances', [
            'event' => $event,
            'rows' => $this->withSignatureDataUris($rows),
            'branding' => $branding,
            'logoDataUri' => $branding->logoDataUri(),
        ])->setPaper('a4', 'landscape')->output();
    }

    /**
     * Ajoute le chemin disque brut de la signature (usage export uniquement —
     * jamais exposé via le flux JSON `feed`, qui reste sur signature_url/route).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function withSignaturePaths(array $rows): array
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
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withSignatureDataUris(array $rows): array
    {
        return array_map(function (array $r): array {
            $path = $r['signature_path'] ?? null;
            $r['signature_data_uri'] = ($path !== null && Storage::disk('local')->exists($path))
                ? 'data:image/png;base64,'.base64_encode((string) Storage::disk('local')->get($path))
                : null;

            return $r;
        }, $this->withSignaturePaths($rows));
    }
}
