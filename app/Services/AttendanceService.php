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

    /**
     * L'événement est-il « en cours » à l'instant $now (fenêtre le couvre, non annulé) ?
     * Conditionne l'anti-chevauchement : un émargement rétroactif sur un événement
     * passé ne doit jamais clôturer une présence en cours ailleurs.
     */
    private function coversNow(Event $event, Carbon $now): bool
    {
        return ! $event->isCancelled()
            && $now->betweenIncluded($event->starts_at, $event->ends_at);
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
     * Enregistre une présence de façon idempotente et sûre vis-à-vis de la concurrence.
     *
     * - Met à jour le profil Personne (dernières valeurs connues) sans dégrader is_staff.
     * - Anti-doublon : la contrainte UNIQUE(event_id, person_id) garantit l'absence de
     *   doublon ; une 2e soumission renvoie la présence existante (même référence).
     * - Anti-chevauchement : si la cible est « en cours » et que la personne est déjà
     *   active sur un autre événement couvrant le même instant, la présence précédente
     *   est clôturée (départ automatique) pour garantir UNE SEULE présence active à la
     *   fois. La CONFIRMATION utilisateur est exigée en amont par les contrôleurs (409
     *   tant que confirm_departure n'est pas fourni) ; ce service est la garde
     *   autoritaire, sous verrou, contre les courses concurrentes.
     */
    public function register(Event $event, AttendanceInput $input): Attendance
    {
        return DB::transaction(function () use ($event, $input): Attendance {
            $person = $this->upsertPerson($input);

            // Verrou pessimiste sur la fiche Personne : sérialise les enregistrements
            // concurrents de la MÊME personne. Sans lui, deux scans simultanés vers
            // deux événements différents pourraient tous deux lire « aucun
            // chevauchement » avant qu'aucun n'ait inséré, puis créer chacun une
            // présence active (la contrainte UNIQUE(event_id, person_id) ne protège
            // que contre un doublon sur le MÊME événement, pas contre deux présences
            // actives sur deux événements distincts). Sous MySQL/InnoDB, le second
            // SELECT ... FOR UPDATE attend la validation (commit) du premier puis
            // revoit l'état à jour. SQLite (tests) n'a pas de verrou de ligne mais
            // sérialise déjà les écritures au niveau base : la logique métier ci-dessous
            // reste correcte, seule la vraie concurrence n'y est pas reproductible.
            Person::query()->whereKey($person->id)->lockForUpdate()->first();

            $existing = Attendance::query()
                ->where('event_id', $event->id)
                ->where('person_id', $person->id)
                ->first();

            if ($existing !== null) {
                return $existing; // idempotent : pas de doublon, on renvoie l'existante
            }

            // Anti-chevauchement AUTORITAIRE, recalculé SOUS VERROU : le pré-contrôle
            // (non verrouillé) du contrôleur peut avoir vu « aucun chevauchement »
            // juste avant qu'une requête concurrente n'insère sa présence. Ce recalcul
            // rattrape cette course et garantit qu'on n'aboutit jamais à DEUX présences
            // actives simultanées. On n'agit QUE si la cible est réellement « en cours »
            // (couvre l'instant) : enregistrer une présence sur un événement PASSÉ
            // (backfill/import) ne doit pas clôturer une présence en cours ailleurs.
            $now = Carbon::now();
            if ($this->coversNow($event, $now)) {
                $overlap = $this->activeOverlap($person, $event, $now);
                if ($overlap !== null) {
                    $this->markDeparture($overlap, $now);
                }
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
                    'checked_in_at' => $now,
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
        $email = Person::normalizeEmail($input->email);

        $person = Person::query()->where('email', $email)->first();
        if ($person !== null) {
            return $person;
        }

        try {
            return Person::create([
                'email' => $email,
                'last_name' => $input->lastName,
                'first_name' => $input->firstName,
                'phone' => $input->phone,
                'company' => $input->company,
                'direction' => $input->direction,
                'service' => $input->service,
                'position' => $input->position,
                'source' => $input->isManual ? PersonSource::Manuel : PersonSource::Emargement,
            ]);
        } catch (QueryException $e) {
            // Course entre deux premières soumissions du même email : le SELECT a vu
            // « personne absente » dans les deux, mais une requête concurrente a gagné
            // l'INSERT entre-temps → violation de la contrainte unique(email). On relit
            // la gagnante pour rester idempotent (cohérent avec register()) au lieu de
            // laisser remonter un 500. `lockForUpdate()` est nécessaire (pas un simple
            // SELECT) : sous MySQL/InnoDB en REPEATABLE READ, un SELECT normal relirait
            // le instantané pris au début de la transaction (qui ne voit pas encore la
            // ligne gagnante tout juste committée par l'autre requête) et renverrait à
            // tort `null`. Un verrou lit toujours la dernière version committée.
            $winner = Person::query()->where('email', $email)->lockForUpdate()->first();
            if ($winner !== null) {
                return $winner;
            }

            throw $e;
        }
    }

    /**
     * Nombre de caractères aléatoires de la référence. La référence sert aussi de
     * clé d'accès à l'avis (/avis/{reference}) exposant l'identité d'une présence :
     * 8 caractères sur un alphabet de 31 symboles ≈ 31^8 ≈ 8,5×10^11 combinaisons,
     * hors de portée d'une énumération par brute-force (couplée au rate-limit des
     * routes d'avis), contre 31^5 ≈ 2,9×10^7 auparavant.
     */
    private const int REFERENCE_LENGTH = 8;

    /** Référence courte et unique (ex. PRS-4F2AK9QT). */
    private function generateReference(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < self::REFERENCE_LENGTH; $i++) {
                $code .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
            }
            $reference = 'PRS-'.$code;
        } while (Attendance::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
