<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Event;
use Illuminate\Support\Carbon;

/**
 * Génère un fichier .ics minimal (RFC 5545) pour un événement, sans dépendance
 * externe — la structure est courte et stable, une lib serait disproportionnée.
 */
final class IcsBuilder
{
    /**
     * Invitation initiale : `METHOD:PUBLISH`, ajout simple à l'agenda.
     */
    public static function forEvent(Event $event): string
    {
        return self::build(
            event: $event,
            start: $event->starts_at,
            end: $event->ends_at,
            method: 'PUBLISH',
            sequence: 0,
        );
    }

    /**
     * Mise à jour d'un événement DÉJÀ invité (report de créneau).
     *
     * On garde le MÊME UID que {@see forEvent()} et on incrémente `SEQUENCE` :
     * c'est la clé pour que les clients calendrier (Outlook, Google, Apple)
     * mettent à jour l'entrée existante au lieu d'en créer une seconde en double.
     * `METHOD:REQUEST` + `ORGANIZER`/`ATTENDEE` sont la forme normalisée d'une
     * ré-invitation ; sans eux, certains clients ignorent la mise à jour.
     *
     * `$start`/`$end` sont passés explicitement (snapshot du report concerné),
     * pas relus sur l'événement : si un second report survenait avant l'envoi de
     * ce mail, le .ics resterait cohérent avec le créneau annoncé dans le corps.
     */
    public static function rescheduleForEvent(
        Event $event,
        Carbon $start,
        Carbon $end,
        int $sequence,
        ?string $attendeeEmail = null,
    ): string {
        return self::build(
            event: $event,
            start: $start,
            end: $end,
            method: 'REQUEST',
            sequence: $sequence,
            attendeeEmail: $attendeeEmail,
        );
    }

    private static function build(
        Event $event,
        Carbon $start,
        Carbon $end,
        string $method,
        int $sequence,
        ?string $attendeeEmail = null,
    ): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Presence//ACS Groupe//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:'.$method,
            'BEGIN:VEVENT',
            'UID:event-'.$event->id.'@presence.acsgroupe.ci',
            'SEQUENCE:'.$sequence,
            'STATUS:CONFIRMED',
            'DTSTAMP:'.self::utc(now()),
            'DTSTART:'.self::utc($start),
            'DTEND:'.self::utc($end),
            'SUMMARY:'.self::escape($event->title),
        ];

        // ORGANIZER/ATTENDEE ne sont pertinents que pour une ré-invitation
        // (METHOD:REQUEST) : ils désignent l'entrée d'agenda à mettre à jour.
        if ($method === 'REQUEST') {
            $organizer = (string) config('mail.from.address');
            if ($organizer !== '') {
                $organizerName = (string) config('mail.from.name');
                $lines[] = 'ORGANIZER;CN='.self::escape($organizerName).':mailto:'.$organizer;
            }

            if ($attendeeEmail !== null && $attendeeEmail !== '') {
                $lines[] = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:'.$attendeeEmail;
            }
        }

        if ($event->location) {
            $lines[] = 'LOCATION:'.self::escape($event->location);
        }

        $lines[] = 'DESCRIPTION:'.self::escape('Émargement sur place — présentez-vous à '.$start->format('H:i').'.');
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    private static function utc(Carbon $date): string
    {
        return $date->clone()->utc()->format('Ymd\THis\Z');
    }

    /** Échappe , ; \ et les retours à la ligne selon RFC 5545. */
    private static function escape(string $value): string
    {
        return str_replace(['\\', ',', ';', "\n"], ['\\\\', '\,', '\;', '\n'], $value);
    }
}
