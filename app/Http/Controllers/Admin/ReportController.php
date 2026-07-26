<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\ReportDocument;
use App\Models\ReportPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents et photos d'un événement (alimentent le Portfolio).
 * Éditables uniquement une fois l'événement commencé.
 *
 * Médias stockés sur le disque PRIVÉ `local` (jamais exposé directement par le
 * serveur web). Le portfolio et les comptes-rendus sont une surface strictement
 * interne, cloisonnée par filiale : un fichier ne doit JAMAIS être accessible via
 * une URL devinable sans authentification. L'accès passe donc par les actions
 * `showDocument`/`showPhoto` de ce contrôleur, servies derrière `auth` +
 * `filiale.scope` + `scopeBindings` — même modèle que la signature manuscrite
 * ({@see AttendanceController::signature()}).
 */
class ReportController extends Controller
{
    /** Ajoute un ou plusieurs documents au compte-rendu. */
    public function uploadDocuments(Request $request, Event $event): JsonResponse
    {
        $this->ensureEditable($event);

        $request->validate([
            'files' => ['required', 'array'],
            'files.*' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt'],
        ]);

        $created = [];
        /** @var UploadedFile $file */
        foreach ($request->file('files', []) as $file) {
            $path = $file->store("reports/{$event->id}/documents", 'local');
            $doc = $event->documents()->create([
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
            $created[] = [
                'id' => $doc->id,
                'name' => $doc->original_name,
                'url' => $doc->url(),
                'size' => $doc->size,
                'delete_url' => route('admin.events.report.documents.destroy', [$event, $doc]),
            ];
        }

        return response()->json(['documents' => $created], 201);
    }

    public function destroyDocument(Event $event, ReportDocument $document): JsonResponse
    {
        Storage::disk('local')->delete($document->path);
        $document->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Sert un document du compte-rendu depuis le disque privé.
     *
     * Aucune vérification de filiale explicite ici : le binding `{event}` est déjà
     * filtré par le global scope de filiale (un événement d'une autre filiale → 404
     * avant même d'entrer dans l'action) et `scopeBindings` garantit que `{document}`
     * appartient bien à CET événement. C'est exactement le modèle éprouvé de la
     * signature manuscrite. Affichage INLINE (ouverture dans l'onglet) : les PDF/
     * images s'ouvrent, les types non affichables sont téléchargés par le navigateur
     * en conservant le nom d'origine. `nosniff` empêche le navigateur de réinterpréter
     * le type MIME.
     */
    public function showDocument(Event $event, ReportDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response(
            $document->path,
            $document->original_name,
            [
                'Content-Type' => $document->mime ?? 'application/octet-stream',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /** Ajoute une ou plusieurs photos au compte-rendu. */
    public function uploadPhotos(Request $request, Event $event): JsonResponse
    {
        $this->ensureEditable($event);

        $request->validate([
            'files' => ['required', 'array'],
            'files.*' => ['file', 'image', 'max:8192', 'mimes:jpg,jpeg,png,webp,gif'],
        ]);

        $position = (int) $event->photos()->max('position');
        $created = [];
        /** @var UploadedFile $file */
        foreach ($request->file('files', []) as $file) {
            $path = $file->store("reports/{$event->id}/photos", 'local');
            $photo = $event->photos()->create([
                'path' => $path,
                'position' => ++$position,
                'uploaded_by' => $request->user()->id,
            ]);
            $created[] = [
                'id' => $photo->id,
                'url' => $photo->url(),
                'delete_url' => route('admin.events.report.photos.destroy', [$event, $photo]),
            ];
        }

        return response()->json(['photos' => $created], 201);
    }

    public function destroyPhoto(Event $event, ReportPhoto $photo): JsonResponse
    {
        Storage::disk('local')->delete($photo->path);
        $photo->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Sert une photo du compte-rendu depuis le disque privé (affichage inline).
     * Même garde-fou que {@see self::showDocument()} : scope de filiale + scopeBindings.
     */
    public function showPhoto(Event $event, ReportPhoto $photo): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response(
            $photo->path,
            null,
            [
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /** Le compte-rendu ne s'édite qu'une fois l'événement commencé. */
    private function ensureEditable(Event $event): void
    {
        abort_unless($event->hasStarted(), 422, "Le compte-rendu s'ouvrira une fois l'événement commencé.");
    }
}
