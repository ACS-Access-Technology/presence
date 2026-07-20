<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie d'un événement : annulation (réversible) et report (changement de
 * créneau avec trace de l'ancien horaire).
 */
class EventLifecycleController extends Controller
{
    /** Annule un événement (les présences déjà enregistrées sont conservées). */
    public function cancel(Request $request, Event $event): RedirectResponse
    {
        $data = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $event->update([
            'cancelled_at' => Carbon::now(),
            'cancellation_reason' => $data['cancellation_reason'] ?? null,
        ]);

        return back()->with('status', 'Événement annulé.');
    }

    /** Réactive un événement annulé. */
    public function uncancel(Event $event): RedirectResponse
    {
        $event->update(['cancelled_at' => null, 'cancellation_reason' => null]);

        return back()->with('status', 'Événement réactivé.');
    }

    /**
     * Reporte un événement : enregistre l'ancien créneau dans l'historique et
     * applique le nouveau. Le mode QR et les présences existantes sont inchangés.
     */
    public function reschedule(Request $request, Event $event): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i', 'after:start'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $newStart = Carbon::parse($data['date'].' '.$data['start']);
        $newEnd = Carbon::parse($data['date'].' '.$data['end']);

        DB::transaction(function () use ($event, $newStart, $newEnd, $data): void {
            $event->reschedules()->create([
                'old_starts_at' => $event->starts_at,
                'old_ends_at' => $event->ends_at,
                'new_starts_at' => $newStart,
                'new_ends_at' => $newEnd,
                'reason' => $data['reason'] ?? null,
                'rescheduled_by' => request()->user()?->id,
            ]);

            // Un report rouvre l'événement s'il avait été clôturé automatiquement.
            $event->update([
                'starts_at' => $newStart,
                'ends_at' => $newEnd,
                'closed_at' => null,
            ]);
        });

        return back()->with('status', 'Événement reporté.');
    }
}
