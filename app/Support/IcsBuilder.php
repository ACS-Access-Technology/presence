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
    public static function forEvent(Event $event): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Presence//ACS Groupe//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:event-'.$event->id.'@presence.acsgroupe.ci',
            'DTSTAMP:'.self::utc(now()),
            'DTSTART:'.self::utc($event->starts_at),
            'DTEND:'.self::utc($event->ends_at),
            'SUMMARY:'.self::escape($event->title),
        ];

        if ($event->location) {
            $lines[] = 'LOCATION:'.self::escape($event->location);
        }

        $lines[] = 'DESCRIPTION:'.self::escape('Émargement sur place — présentez-vous à '.$event->starts_at->format('H:i').'.');
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
