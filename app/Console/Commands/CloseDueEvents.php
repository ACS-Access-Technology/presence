<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendAttendanceConfirmation;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Scopes\FilialeScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Clôture les événements terminés et met en file l'email de confirmation SIMPLE de
 * chaque personne émargée. Exécutée chaque minute par le scheduler (cron cPanel
 * unique). Idempotente : jamais de double clôture ni de double email.
 *
 * ⚠️ Deux étapes DÉCOUPLÉES et indépendamment idempotentes (audit cycle de vie
 * email, bug #1) :
 *
 *   1. {@see closeDueEvents()} — pose `closed_at` sur les événements terminés.
 *   2. {@see queuePendingReportEmails()} — met en file les emails des événements
 *      clos dont le batch n'a pas encore été mis en file (`report_email_queued_at`
 *      nul), INDÉPENDAMMENT de savoir si la clôture vient d'avoir lieu ou date d'un
 *      passage précédent.
 *
 * Auparavant les deux étaient enchaînées : si le process plantait ENTRE la pose de
 * `closed_at` et la mise en file, l'événement — déjà clos — n'était jamais
 * reconsidéré (le filtre portait sur `closed_at IS NULL`) et l'email n'était JAMAIS
 * envoyé, silencieusement. Désormais la passe email rattrape tout événement clos
 * resté sans email au passage suivant.
 */
class CloseDueEvents extends Command
{
    protected $signature = 'events:close-due';

    protected $description = 'Clôture les événements terminés et envoie les emails de confirmation.';

    public function handle(): int
    {
        $closed = $this->closeDueEvents();
        $queued = $this->queuePendingReportEmails();

        $this->info("{$closed} événement(s) clôturé(s), {$queued} email(s) de confirmation en file.");

        return self::SUCCESS;
    }

    /**
     * Passe 1 — clôture. Pose `closed_at` sur les événements dont la fenêtre
     * effective (avec délai de grâce éventuel) est dépassée. N'envoie AUCUN email :
     * c'est le rôle exclusif de la passe 2.
     *
     * La sélection sur la BORNE EFFECTIVE (`checkInClosesAt`) et non `ends_at`
     * reporte la clôture de 15 min pour un événement en grâce (§ 6.2, RME-6).
     *
     * `withoutGlobalScope` : défense en profondeur (RME-2). Le contexte de scoping
     * est déjà inactif en CLI (le cron ne passe pas par le middleware admin), donc
     * c'est un no-op ; on le rend explicite pour verrouiller l'invariant « le cron
     * traite TOUTES les filiales ».
     */
    private function closeDueEvents(): int
    {
        $now = Carbon::now();
        $graceBefore = $now->copy()->subMinutes(Event::GRACE_CHECK_IN_MINUTES);

        $due = Event::query()
            ->withoutGlobalScope(FilialeScope::class)
            ->whereNull('closed_at')
            ->whereNull('cancelled_at')
            ->where(function ($query) use ($now, $graceBefore): void {
                $query
                    ->where(fn ($q) => $q->where('grace_check_in_enabled', false)->where('ends_at', '<', $now))
                    ->orWhere(fn ($q) => $q->where('grace_check_in_enabled', true)->where('ends_at', '<', $graceBefore));
            })
            ->get();

        $closed = 0;

        foreach ($due as $event) {
            // `withoutOverlapping()` empêche déjà deux exécutions concurrentes de la
            // commande, mais un verrou explicite protège aussi contre une exécution
            // manuelle qui chevaucherait le scheduler (double clôture).
            $justClosed = DB::transaction(function () use ($event): bool {
                $locked = Event::query()->withoutGlobalScope(FilialeScope::class)
                    ->whereKey($event->id)->lockForUpdate()->first();
                if ($locked === null || $locked->closed_at !== null) {
                    return false;
                }

                $locked->forceFill(['closed_at' => Carbon::now()])->save();

                return true;
            });

            if ($justClosed) {
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Passe 2 — emails. Met en file l'email de confirmation de chaque présence des
     * événements CLOS dont le batch n'a pas encore été traité
     * (`report_email_queued_at` nul), que la clôture vienne d'avoir lieu (passe 1)
     * ou remonte à un passage antérieur (rattrapage après crash).
     *
     * Anti-doublon : chaque présence est marquée `confirmation_email_queued_at` puis
     * ignorée aux passages suivants. L'envoi RÉEL n'est confirmé que plus tard par
     * {@see SendAttendanceConfirmation}, qui pose `confirmation_email_sent_at`.
     *
     * @return int Nombre d'emails réellement mis en file (hors clés synthétiques).
     */
    private function queuePendingReportEmails(): int
    {
        $pending = Event::query()
            ->withoutGlobalScope(FilialeScope::class)
            ->whereNotNull('closed_at')
            ->whereNull('cancelled_at')
            ->whereNull('report_email_queued_at')
            ->get();

        $queued = 0;

        foreach ($pending as $event) {
            $queued += DB::transaction(function () use ($event): int {
                // Verrou : évite qu'une exécution concurrente mette le batch en file
                // deux fois (double email).
                $locked = Event::query()->withoutGlobalScope(FilialeScope::class)
                    ->whereKey($event->id)->lockForUpdate()->first();

                if ($locked === null
                    || $locked->closed_at === null
                    || $locked->cancelled_at !== null
                    || $locked->report_email_queued_at !== null) {
                    return 0;
                }

                $count = $this->queueEventBatch($locked);

                // Marqueur de batch posé dans LA MÊME transaction que les dispatch de
                // jobs (file `database` en prod) : mise en file et marqueur commitent
                // atomiquement. Un crash avant commit ⇒ rien n'est persisté ⇒ le
                // batch est rejoué au passage suivant (pas de perte, pas de doublon).
                $locked->forceFill(['report_email_queued_at' => Carbon::now()])->save();

                return $count;
            });
        }

        return $queued;
    }

    /**
     * Met en file les emails d'un événement (verrouillé). Une présence déjà tentée
     * (`confirmation_email_queued_at` non nul) est ignorée — rattrapage sûr des
     * présences ajoutées après un report (bug #3) sans re-notifier les autres.
     */
    private function queueEventBatch(Event $event): int
    {
        $count = 0;

        $event->attendances()
            ->whereNull('confirmation_email_queued_at')
            ->with('person')
            ->chunkById(200, function ($attendances) use (&$count): void {
                /** @var Attendance $attendance */
                foreach ($attendances as $attendance) {
                    $now = Carbon::now();
                    $email = $attendance->person?->email;

                    // Clé synthétique (présence manuelle sans vrai email) : rien à
                    // envoyer. On marque queued ET sent pour la considérer traitée de
                    // bout en bout (elle ne relèvera jamais d'un envoi réel).
                    if ($email === null || Str::endsWith($email, '@presence.local')) {
                        $attendance->forceFill([
                            'confirmation_email_queued_at' => $now,
                            'confirmation_email_sent_at' => $now,
                        ])->save();

                        continue;
                    }

                    $attendance->forceFill(['confirmation_email_queued_at' => $now])->save();
                    SendAttendanceConfirmation::dispatch($attendance->id);
                    $count++;
                }
            });

        return $count;
    }
}
