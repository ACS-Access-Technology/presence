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

/**
 * Compte-rendu d'un événement : texte (markdown), documents et photos.
 * Le compte-rendu n'est éditable qu'une fois l'événement commencé.
 * Médias stockés sur le disque public (portfolio interne).
 */
class ReportController extends Controller
{
    /** Enregistre le texte du compte-rendu. */
    public function saveText(Request $request, Event $event): JsonResponse
    {
        $this->ensureEditable($event);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:20000'],
        ]);

        $event->report()->updateOrCreate(
            ['event_id' => $event->id],
            ['body' => $validated['body'] ?? null, 'updated_by' => $request->user()->id],
        );

        return response()->json(['saved_at' => now()->format('H:i')]);
    }

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
            $path = $file->store("reports/{$event->id}/documents", 'public');
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
        Storage::disk('public')->delete($document->path);
        $document->delete();

        return response()->json(['deleted' => true]);
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
            $path = $file->store("reports/{$event->id}/photos", 'public');
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
        Storage::disk('public')->delete($photo->path);
        $photo->delete();

        return response()->json(['deleted' => true]);
    }

    /** Le compte-rendu ne s'édite qu'une fois l'événement commencé. */
    private function ensureEditable(Event $event): void
    {
        abort_unless($event->hasStarted(), 422, "Le compte-rendu s'ouvrira une fois l'événement commencé.");
    }
}
