<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PersonSource;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Person;
use App\Services\Attendance\AttendanceInput;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Logique métier des présences : reconnaissance par email, anti-chevauchement
 * (checkout explicite), idempotence / anti-doublon, snapshot et départs.
 */
final class AttendanceService
{
    /** Alphabet des références, sans caractères ambigus (pas de O/0/I/1/L). */
    private const string REFERENCE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Retrouve la fiche Personne correspondant à un email (reconnaissance). */
    public function findPersonByEmail(string $email): ?Person
    {
        return Person::query()
            ->where('email', Person::normalizeEmail($email))
            ->first();
    }

    /**
     * Présence active (sans départ) de la personne sur un AUTRE événement dont la
     * fenêtre chevauche l'instant courant. Sert au choix anti-chevauchement.
     */
    public function activeOverlap(Person $person, Event $current, ?Carbon $now = null): ?Attendance
    {
        $now ??= Carbon::now();

        return Attendance::query()
            ->where('person_id', $person->id)
            ->where('event_id', '!=', $current->id)
            ->whereNull('departed_at')
            ->whereHas('event', function ($query) use ($now): void {
                $query->whereNull('cancelled_at')
                    ->where('starts_at', '<=', $now)
                    ->where('ends_at', '>=', $now);
            })
            ->with('event')
            ->first();
    }

    /** Récurrent = a déjà émargé sur un événement ANTÉRIEUR à celui-ci. */
    public function isRecurrent(Person $person, Event $event): bool
    {
        return Attendance::query()
            ->where('person_id', $person->id)
            ->where('event_id', '!=', $event->id)
            ->whereHas('event', fn ($q) => $q->where('starts_at', '<', $event->starts_at))
            ->exists();
    }

    /**
     * Enregistre une présence de façon idempotente.
     *
     * - Met à jour le profil Personne (dernières valeurs connues) sans dégrader is_staff.
     * - Si $departFrom est fourni (chevauchement confirmé), enregistre le départ correspondant.
     * - Anti-doublon : la contrainte UNIQUE(event_id, person_id) garantit l'absence de
     *   doublon ; une 2e soumission renvoie la présence existante (même référence).
     */
    public function register(Event $event, AttendanceInput $input, ?Attendance $departFrom = null): Attendance
    {
        return DB::transaction(function () use ($event, $input, $departFrom): Attendance {
            $person = $this->upsertPerson($input);

            $existing = Attendance::query()
                ->where('event_id', $event->id)
                ->where('person_id', $person->id)
                ->first();

            if ($existing !== null) {
                return $existing; // idempotent : pas de doublon, on renvoie l'existante
            }

            // Chevauchement confirmé : on clôture la présence précédente avant de créer.
            if ($departFrom !== null) {
                $this->markDeparture($departFrom);
            }

            try {
                return Attendance::create([
                    'event_id' => $event->id,
                    'person_id' => $person->id,
                    'last_name' => $input->lastName,
                    'first_name' => $input->firstName,
                    'phone' => $input->phone,
                    'company' => $input->company,
                    'direction' => $input->direction,
                    'service' => $input->service,
                    'position' => $input->position,
                    'signature_path' => $input->signaturePath,
                    'latitude' => $input->latitude,
                    'longitude' => $input->longitude,
                    'accuracy' => $input->accuracy,
                    'is_manual' => $input->isManual,
                    'manual_confirmed' => $input->manualConfirmed,
                    'recorded_by' => $input->recordedBy,
                    'checked_in_at' => Carbon::now(),
                    'reference' => $this->generateReference(),
                ]);
            } catch (QueryException $e) {
                // Course entre deux requêtes concurrentes : la contrainte unique a
                // sauté. On relit la présence gagnante (idempotence garantie).
                $winner = Attendance::query()
                    ->where('event_id', $event->id)
                    ->where('person_id', $person->id)
                    ->first();

                if ($winner !== null) {
                    return $winner;
                }

                throw $e;
            }
        });
    }

    /** Marque un départ (départ définitif, informatif — n'affecte aucun KPI). */
    public function markDeparture(Attendance $attendance, ?Carbon $at = null): Attendance
    {
        if ($attendance->departed_at === null) {
            $attendance->departed_at = $at ?? Carbon::now();
            $attendance->save();
        }

        return $attendance;
    }

    /** Annule un départ (départ manuel posé par erreur). */
    public function undoDeparture(Attendance $attendance): Attendance
    {
        if ($attendance->departed_at !== null) {
            $attendance->departed_at = null;
            $attendance->save();
        }

        return $attendance;
    }

    /**
     * Crée ou met à jour la fiche Personne à partir des valeurs déclarées.
     * Ne dégrade jamais is_staff ; ne fixe la source qu'à la création.
     */
    /**
     * Retrouve ou crée la fiche Personne (référentiel). Un email connu N'EST
     * PAS écrasé par la soumission publique (non authentifiée) : seule la
     * création d'une nouvelle fiche renseigne les champs, pour empêcher
     * quiconque connaissant/devinant un email de corrompre une fiche existante.
     * L'Attendance elle-même conserve son propre instantané des champs soumis.
     */
    private function upsertPerson(AttendanceInput $input): Person
    {
        $person = Person::query()->where('email', Person::normalizeEmail($input->email))->first();
        if ($person !== null) {
            return $person;
        }

        return Person::create([
            'email' => Person::normalizeEmail($input->email),
            'last_name' => $input->lastName,
            'first_name' => $input->firstName,
            'phone' => $input->phone,
            'company' => $input->company,
            'direction' => $input->direction,
            'service' => $input->service,
            'position' => $input->position,
            'source' => $input->isManual ? PersonSource::Manuel : PersonSource::Emargement,
        ]);
    }

    /** Référence courte et unique (ex. PRS-4F2A9). */
    private function generateReference(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 5; $i++) {
                $code .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
            }
            $reference = 'PRS-'.$code;
        } while (Attendance::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
