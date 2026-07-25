<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\QrMode;
use App\Http\Controllers\Controller;
use App\Mail\EventRescheduledMail;
use App\Models\Event;
use App\Models\EventReschedule;
use App\Models\Person;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Enum;

/**
 * Cycle de vie d'un événement : annulation (réversible), report (changement de
 * créneau avec trace de l'ancien horaire) et mode QR (modifiable jusqu'à la
 * première présence, verrouillé ensuite — cf. Event::isQrModeLocked()).
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

        $reschedule = DB::transaction(function () use ($event, $newStart, $newEnd, $data): EventReschedule {
            $reschedule = $event->reschedules()->create([
                'old_starts_at' => $event->starts_at,
                'old_ends_at' => $event->ends_at,
                'new_starts_at' => $newStart,
                'new_ends_at' => $newEnd,
                'reason' => $data['reason'] ?? null,
                'rescheduled_by' => request()->user()?->id,
            ]);

            // Un report rouvre l'événement s'il avait été clôturé automatiquement.
            // On réarme aussi le batch d'emails (`report_email_queued_at` → null) :
            // sinon, à la prochaine clôture (nouvelle date), le cron considérerait le
            // batch déjà traité et n'émaillerait JAMAIS les présences ajoutées après
            // le report (bug #3).
            //
            // On NE réinitialise PAS les `confirmation_email_*` des présences déjà
            // enregistrées : ce sont les emails de CONFIRMATION de présence (récap de
            // clôture), pas la notification de report. Les re-déclencher enverrait un
            // second « merci d'avoir participé ». La notification du changement
            // d'horaire est un email dédié (voir ci-dessous), envoyé à tous.
            $event->update([
                'starts_at' => $newStart,
                'ends_at' => $newEnd,
                'closed_at' => null,
                'report_email_queued_at' => null,
            ]);

            return $reschedule;
        });

        $this->notifyReschedule($event, $reschedule);

        return back()->with('status', 'Événement reporté.');
    }

    /**
     * Notifie du nouveau créneau toutes les personnes CONCERNÉES par CETTE séance :
     * les invités ET celles qui ont déjà émargé (cas d'un report après une clôture).
     * L'union est dédupliquée par personne — quelqu'un à la fois invité et présent
     * ne reçoit qu'un seul email.
     *
     * Portée = un seul `Event` : dans une série, chaque séance a ses propres
     * invitations (cf. EventController::store), donc reporter une séance ne notifie
     * jamais les invités des autres séances.
     *
     * `$sequence` = nombre de reports déjà appliqués à l'événement : c'est le
     * compteur monotone requis par le .ics (`SEQUENCE`) pour que les agendas
     * traitent la pièce jointe comme une mise à jour, pas comme un doublon.
     */
    private function notifyReschedule(Event $event, EventReschedule $reschedule): void
    {
        $personIds = $event->invitations()->pluck('person_id')
            ->merge($event->attendances()->pluck('person_id'))
            ->unique()
            ->values();

        if ($personIds->isEmpty()) {
            return;
        }

        $sequence = $event->reschedules()->count();

        Person::whereIn('id', $personIds)->each(
            fn (Person $person) => Mail::to($person->email)
                ->queue(new EventRescheduledMail($person, $reschedule, $sequence)),
        );
    }

    /**
     * Change le mode QR (statique ↔ tournant) avant la première présence. Le
     * `qr_secret` de l'événement reste inchangé : seule la façon de le diffuser
     * change, pas la racine HMAC.
     */
    public function changeQrMode(Request $request, Event $event): RedirectResponse
    {
        if ($event->isQrModeLocked()) {
            return back()->with('status', 'Mode QR verrouillé : une présence existe déjà pour cet événement.');
        }

        $data = $request->validate([
            'qr_mode' => ['required', new Enum(QrMode::class)],
        ]);

        // Le mode statique n'a, contrairement au tournant, aucune protection HMAC
        // contre une photo réutilisée à distance : le périmètre géographique est
        // sa seule anti-fraude réelle. Rouvre la même exigence qu'à la création/
        // modification (StoreEventRequest/UpdateEventRequest) — sinon ce endpoint
        // recrée la faille par une autre porte.
        if ($data['qr_mode'] === QrMode::Statique->value && ! $event->hasGeofence()) {
            return back()->with('status', 'Passage en QR statique refusé : configurez d\'abord un périmètre géographique (modifier l\'événement) — sans lui, ce mode n\'offre aucune protection anti-fraude réelle.');
        }

        $event->update(['qr_mode' => $data['qr_mode']]);

        return back()->with('status', 'Mode QR modifié.');
    }
}
