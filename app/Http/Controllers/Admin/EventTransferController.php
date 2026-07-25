<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TransferEventRequest;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Scopes\FilialeScope;
use App\Services\EventTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Transfert d'un événement (ou de sa série) vers une autre filiale — SuperAdmin
 * uniquement (route `role:super_admin`, T-ME-14). Miroir de l'action
 * {@see AccountController::reassign()} pour la cohérence, mais un événement
 * transporte bien plus de données liées (présences, invitations, compte-rendu) :
 * la logique vit dans {@see EventTransferService}.
 *
 * Réassignation de COMPTE ≠ transfert d'ÉVÉNEMENT : deux opérations distinctes et
 * volontairement séparées. Réassigner un compte ne déplace PAS ses événements
 * (comportement inchangé de `reassign`).
 */
final class EventTransferController extends Controller
{
    public function __construct(private readonly EventTransferService $service) {}

    public function transfer(TransferEventRequest $request, Event $event): RedirectResponse
    {
        // Défense en profondeur : la route est déjà `role:super_admin`.
        Gate::authorize('transfer', $event);

        $data = $request->validated();

        $targetFiliale = Filiale::findOrFail((int) $data['filiale_id']);
        // Le type est dans la filiale CIBLE, différente du contexte de scoping
        // courant → on lève le global scope pour le résoudre (la validation a déjà
        // garanti son appartenance à la filiale cible).
        $targetType = EventType::withoutGlobalScope(FilialeScope::class)->findOrFail((int) $data['event_type_id']);

        $wholeSeries = $data['scope'] === 'series';

        $moved = $this->service->transfer($event, $targetFiliale, $targetType, $wholeSeries, $request->user());

        $message = count($moved) > 1
            ? 'Série transférée vers '.$targetFiliale->name.' ('.count($moved).' séances).'
            : 'Événement transféré vers '.$targetFiliale->name.'.';

        // Redirection vers la liste (et non la fiche) : si le SuperAdmin a
        // sélectionné la filiale SOURCE comme contexte, la fiche de l'événement
        // déplacé serait désormais hors scope (404). La liste, elle, se réévalue
        // proprement selon le contexte courant.
        return redirect()->route('admin.events.index')->with('status', $message);
    }
}
