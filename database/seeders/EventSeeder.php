<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\QrMode;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use App\Services\Attendance\AttendanceInput;
use App\Services\AttendanceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Événements de démonstration (dev) : un événement en cours peuplé de présences,
 * un à venir, un clos et un annulé — pour tester liste, détail, stats et exports.
 */
class EventSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(AttendanceService::class);
        $type = fn (string $name): int => EventType::where('name', $name)->value('id');
        $organiser = User::where('email', 'organisateur@acsgroupe.ci')->first();

        // Événement CLOS passé — sert à rendre certaines personnes « récurrentes ».
        $past = $this->event('Séminaire Sécurité 2026', $type('Formation'), QrMode::Statique,
            Carbon::now()->subDays(7)->setTime(9, 0), Carbon::now()->subDays(7)->setTime(12, 0),
            'Amphi Palmier', closedAt: Carbon::now()->subDays(7)->setTime(12, 0));
        $this->attend($service, $past, 'awa.kone@acsgroupe.ci', 'Koné', 'Awa', 'ACS Groupe', 'Direction SI', 'Analyste sécurité');
        $this->attend($service, $past, 'k.ndri@acsgroupe.ci', "N'Dri", 'Kouassi', 'ACS Groupe', 'Direction SI', 'Admin systèmes');

        // Événement EN COURS — peuplé (mix nouveaux/récurrents, un départ, un manuel).
        $live = $this->event('Atelier Cybersécurité', $type('Atelier'), QrMode::Tournant,
            Carbon::now()->subMinutes(50), Carbon::now()->addHours(2), 'Salle Ébène, Cocody');

        $a1 = $this->attend($service, $live, 'awa.kone@acsgroupe.ci', 'Koné', 'Awa', 'ACS Groupe', 'Direction SI', 'Analyste sécurité', 47);
        $this->attend($service, $live, 'm.traore@orange.ci', 'Traoré', 'Mamadou', 'Orange CI', 'Direction Technique', 'Ingénieur réseau', 45, departedMinAgo: 8);
        $this->attend($service, $live, 'f.diallo@nsia.ci', 'Diallo', 'Fatou', 'NSIA Banque', 'Direction Risques', 'Responsable conformité', 40);
        $this->attend($service, $live, 'k.ndri@acsgroupe.ci', "N'Dri", 'Kouassi', 'ACS Groupe', 'Direction SI', 'Admin systèmes', 38);
        // Présence saisie manuellement par l'organisateur (sans géoloc/signature).
        $this->attendManual($service, $live, 'a.bamba@mtn.ci', 'Bamba', 'Aya', 'MTN CI', 'Direction Commerciale', 'Key Account Manager', $organiser?->id, 30);

        // Événement À VENIR.
        $this->event('Réunion Comité SI', $type('Réunion'), QrMode::Statique,
            Carbon::now()->addDay()->setTime(10, 0), Carbon::now()->addDay()->setTime(11, 30), 'Salle Acajou');

        // Événement ANNULÉ.
        $this->event('Conférence Cloud & IA', $type('Conférence'), QrMode::Tournant,
            Carbon::now()->addDays(3)->setTime(14, 0), Carbon::now()->addDays(3)->setTime(17, 0),
            'Auditorium', cancelledAt: Carbon::now()->subDay());
    }

    private function event(string $title, int $typeId, QrMode $mode, Carbon $start, Carbon $end, ?string $location = null, ?Carbon $closedAt = null, ?Carbon $cancelledAt = null): Event
    {
        return Event::updateOrCreate(
            ['public_slug' => Str::slug($title)],
            [
                'title' => $title, 'event_type_id' => $typeId,
                'starts_at' => $start, 'ends_at' => $end, 'location' => $location,
                'qr_mode' => $mode->value, 'qr_secret' => Str::random(32),
                'closed_at' => $closedAt, 'cancelled_at' => $cancelledAt,
            ],
        );
    }

    private function attend(AttendanceService $s, Event $e, string $email, string $last, string $first, string $company, string $direction, string $position, int $minAgo = 40, ?int $departedMinAgo = null)
    {
        $a = $s->register($e, new AttendanceInput(
            email: $email, lastName: $last, firstName: $first,
            phone: '+225 07 00 00 00 00', company: $company, direction: $direction, position: $position,
            latitude: 5.35, longitude: -4.01, accuracy: 12.0,
            signaturePath: $this->fakeSignature($e->id),
        ));
        $a->forceFill(['checked_in_at' => Carbon::now()->subMinutes($minAgo)])->save();
        if ($departedMinAgo !== null) {
            $s->markDeparture($a, Carbon::now()->subMinutes($departedMinAgo));
        }

        return $a;
    }

    private function attendManual(AttendanceService $s, Event $e, string $email, string $last, string $first, string $company, string $direction, string $position, ?int $userId, int $minAgo): void
    {
        $a = $s->register($e, new AttendanceInput(
            email: $email, lastName: $last, firstName: $first,
            phone: '+225 05 00 00 00 00', company: $company, direction: $direction, position: $position,
            isManual: true, manualConfirmed: true, recordedBy: $userId,
        ));
        $a->forceFill(['checked_in_at' => Carbon::now()->subMinutes($minAgo)])->save();
    }

    /** Génère une signature factice (scribble) — les vraies présences en ont toujours une (formulaire public l'exige). */
    private function fakeSignature(int $eventId): string
    {
        $im = imagecreatetruecolor(300, 120);
        $white = imagecolorallocate($im, 255, 255, 255);
        $ink = imagecolorallocate($im, 30, 42, 120);
        imagefill($im, 0, 0, $white);
        $points = [20, 90, 60, 30, 100, 100, 140, 20, 180, 90, 220, 40, 260, 80];
        imagesetthickness($im, 3);
        for ($i = 0; $i < count($points) - 2; $i += 2) {
            imageline($im, $points[$i], $points[$i + 1], $points[$i + 2], $points[$i + 3], $ink);
        }
        ob_start();
        imagepng($im);
        $binary = ob_get_clean();
        imagedestroy($im);

        $path = 'signatures/'.$eventId.'/'.Str::uuid()->toString().'.png';
        Storage::disk('local')->put($path, $binary);

        return $path;
    }
}
