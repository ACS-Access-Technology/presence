<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Scopes\FilialeScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Transfert d'un événement (ou d'une série entière) vers une autre filiale de la
 * holding — opération SuperAdmin (T-ME-14, réintroduite après feu vert client).
 *
 * Principes :
 *  - Ce qui est rattaché à l'événement par `event_id` (présences, invitations,
 *    compte-rendu + documents + photos) SUIT automatiquement : ces tables ne
 *    portent PAS de `filiale_id`, elles s'appuient sur l'événement. Rien n'est
 *    déplacé ni ré-écrit côté médias (le stockage privé reste identique). On ne
 *    touche donc QUE `events.filiale_id` et `events.event_type_id`.
 *  - Le référentiel `Person` reste unifié holding (D-ME-1) : jamais modifié.
 *  - Le type d'événement est propre à chaque filiale (Q-ME-10, unicité
 *    `(filiale_id, name)`) : l'ancien `event_type_id` n'a aucun sens dans la
 *    filiale cible. Le nouveau type, choisi explicitement, DOIT appartenir à la
 *    filiale de destination (garde ci-dessous, doublée par la validation).
 *  - Série vs séance : arbitrage documenté ci-dessous ({@see eventsToMove()}).
 *  - Idempotence de la trace : une opération = une entrée d'audit.
 */
final class EventTransferService
{
    /**
     * Déplace l'événement (et éventuellement toute sa série) vers `$targetFiliale`
     * en lui affectant `$targetType`. Retourne les événements effectivement
     * déplacés.
     *
     * @return list<Event>
     */
    public function transfer(
        Event $event,
        Filiale $targetFiliale,
        EventType $targetType,
        bool $wholeSeries,
        ?User $actor,
    ): array {
        if ($targetType->filiale_id !== $targetFiliale->id) {
            throw new \InvalidArgumentException(
                'Le type d\'événement choisi n\'appartient pas à la filiale de destination.',
            );
        }

        return DB::transaction(function () use ($event, $targetFiliale, $targetType, $wholeSeries, $actor): array {
            // Instantané AVANT toute mutation (l'événement acté peut faire partie
            // du lot déplacé — on fige donc ses valeurs d'origine d'abord).
            $sourceFilialeId = $event->filiale_id;
            $sourceFilialeName = $event->filiale?->name;
            $sourceTypeId = $event->event_type_id;

            $wholeSeries = $wholeSeries && $event->event_series_id !== null;
            $events = $this->eventsToMove($event, $wholeSeries);

            foreach ($events as $moved) {
                $moved->update([
                    'filiale_id' => $targetFiliale->id,
                    'event_type_id' => $targetType->id,
                ]);
            }

            if ($wholeSeries) {
                // Série entière : on aligne aussi le gabarit (la série n'a pas de
                // `filiale_id`, mais son `event_type_id` doit rester cohérent avec
                // les séances désormais dans la filiale cible).
                $event->series()->first()?->update(['event_type_id' => $targetType->id]);
            } elseif ($event->event_series_id !== null) {
                // Séance seule d'une série : on la DÉTACHE de sa série. Une série ne
                // peut pas s'étendre proprement sur deux filiales (son type est
                // scopé à une filiale, et le cloisonnement rendrait de toute façon
                // les séances-sœurs invisibles entre elles). Détacher garde la
                // donnée cohérente ; les autres séances restent dans la série
                // d'origine, avec leurs positions inchangées.
                $event->update(['event_series_id' => null, 'series_position' => null]);
            }

            $eventIds = array_map(static fn (Event $e): int => $e->id, $events);

            AuditLog::record(
                action: 'event.transferred',
                subject: $event,
                before: [
                    'filiale_id' => $sourceFilialeId,
                    'filiale_name' => $sourceFilialeName,
                    'event_type_id' => $sourceTypeId,
                    'scope' => $wholeSeries ? 'series' : 'seance',
                    'event_ids' => $eventIds,
                ],
                after: [
                    'filiale_id' => $targetFiliale->id,
                    'filiale_name' => $targetFiliale->name,
                    'event_type_id' => $targetType->id,
                    'event_type_name' => $targetType->name,
                    'event_ids' => $eventIds,
                ],
                actor: $actor,
            );

            return $events;
        });
    }

    /**
     * Ensemble des événements à déplacer. Pour une série entière, on lève le
     * global scope de filiale : c'est une opération système qui ne doit rater
     * aucune séance, indépendamment du contexte de filiale sélectionné par le
     * SuperAdmin (en pratique toutes les séances partagent la même filiale).
     *
     * @return list<Event>
     */
    private function eventsToMove(Event $event, bool $wholeSeries): array
    {
        if ($wholeSeries) {
            return Event::withoutGlobalScope(FilialeScope::class)
                ->where('event_series_id', $event->event_series_id)
                ->get()
                ->all();
        }

        return [$event];
    }
}
