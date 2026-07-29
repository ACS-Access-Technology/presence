<?php

declare(strict_types=1);

namespace App\Services;

use App\Console\Commands\CloseDueEvents;
use App\Enums\RecruitmentCandidatureStatus;
use App\Enums\UserRole;
use App\Mail\RecruitmentCandidatureSubmittedMail;
use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentReponse;
use App\Models\RecruitmentTest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Orchestration d'une candidature au test de recrutement en ligne. Renvoie
 * `null` (jamais d'exception) quand une précondition métier échoue — le
 * contrôleur public traduit `null` en réponse HTTP adaptée (422/410).
 */
final class RecruitmentService
{
    public function __construct(private readonly RecruitmentScoringService $scoring) {}

    /**
     * @param  array{nom: string, prenom: string, email: string, telephone: ?string}  $identite
     */
    public function startCandidature(RecruitmentTest $test, array $identite): ?RecruitmentCandidature
    {
        $exists = RecruitmentCandidature::where('recruitment_test_id', $test->id)
            ->where('email', $identite['email'])
            ->exists();

        if ($exists) {
            return null;
        }

        return RecruitmentCandidature::create([
            'recruitment_test_id' => $test->id,
            'nom' => $identite['nom'],
            'prenom' => $identite['prenom'],
            'email' => $identite['email'],
            'telephone' => $identite['telephone'] ?? null,
            'token' => Str::random(40),
            'started_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes($test->duree_minutes),
            'statut' => RecruitmentCandidatureStatus::EnCours,
        ]);
    }

    /**
     * Verrouillée comme {@see submit()}/{@see closeExpired()} : sans ce verrou,
     * une réponse pourrait s'écrire après qu'un `submit()`/`closeExpired()`
     * concurrent a déjà noté et notifié la candidature (réponse orpheline sur
     * une candidature déjà scorée).
     *
     * @param  array{classement?: ?string, ajustement_tva?: ?int, retenue_attendue?: ?int}  $data
     */
    public function saveReponse(RecruitmentCandidature $candidature, RecruitmentQuestion $question, array $data): ?RecruitmentReponse
    {
        return DB::transaction(function () use ($candidature, $question, $data): ?RecruitmentReponse {
            $locked = RecruitmentCandidature::whereKey($candidature->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->estEnCours()) {
                return null;
            }

            return RecruitmentReponse::updateOrCreate(
                [
                    'recruitment_candidature_id' => $locked->id,
                    'recruitment_question_id' => $question->id,
                ],
                [
                    'classement_saisi' => $data['classement'] ?? null,
                    'ajustement_tva_saisi' => $data['ajustement_tva'] ?? null,
                    'retenue_saisie' => $data['retenue_attendue'] ?? null,
                ],
            );
        });
    }

    /**
     * Verrouillée comme {@see closeExpired()} (même motif : lecture-puis-écriture
     * sans verrou serait une course avec la clôture planifiée). Un candidat qui
     * soumet au même instant où `closeExpired()` clôture la même ligne pour
     * dépassement de délai ne doit JAMAIS écraser le statut `Expire` déjà noté
     * et notifié — l'horloge serveur fait foi, pas l'ordre d'arrivée des requêtes.
     */
    public function submit(RecruitmentCandidature $candidature): ?RecruitmentCandidature
    {
        $submitted = DB::transaction(function () use ($candidature): ?RecruitmentCandidature {
            $locked = RecruitmentCandidature::whereKey($candidature->id)->lockForUpdate()->first();

            if ($locked === null || $locked->statut !== RecruitmentCandidatureStatus::EnCours) {
                return null;
            }

            $locked->forceFill([
                'statut' => RecruitmentCandidatureStatus::Soumis,
                'submitted_at' => Carbon::now(),
                'score' => $this->scoring->scoreCandidature($locked),
            ])->save();

            return $locked;
        });

        if ($submitted !== null) {
            $this->notifyManagers($submitted);
        }

        return $submitted;
    }

    /**
     * Clôture et note toute candidature `en_cours` dont le délai est dépassé.
     * Idempotente : verrou par ligne (comme {@see CloseDueEvents}),
     * un deuxième passage sur une candidature déjà traitée est un no-op.
     */
    public function closeExpired(): int
    {
        $count = 0;

        $expired = RecruitmentCandidature::where('statut', RecruitmentCandidatureStatus::EnCours)
            ->where('expires_at', '<', Carbon::now())
            ->get();

        foreach ($expired as $candidature) {
            $justClosed = DB::transaction(function () use ($candidature): bool {
                $locked = RecruitmentCandidature::whereKey($candidature->id)->lockForUpdate()->first();

                if ($locked === null || $locked->statut !== RecruitmentCandidatureStatus::EnCours) {
                    return false;
                }

                $locked->forceFill([
                    'statut' => RecruitmentCandidatureStatus::Expire,
                    'submitted_at' => Carbon::now(),
                    'score' => $this->scoring->scoreCandidature($locked),
                ])->save();

                return true;
            });

            if ($justClosed) {
                $count++;
                $this->notifyManagers($candidature->fresh());
            }
        }

        return $count;
    }

    /** Notifie les managers (AdminFiliale de la filiale du poste + tout SuperAdmin). */
    private function notifyManagers(RecruitmentCandidature $candidature): void
    {
        $test = $candidature->test;

        $managers = User::where(function ($query) use ($test): void {
            $query->where('filiale_id', $test->filiale_id)->where('role', UserRole::AdminFiliale);
        })->orWhere('role', UserRole::SuperAdmin)->get();

        foreach ($managers as $manager) {
            Mail::to($manager->email)->queue(new RecruitmentCandidatureSubmittedMail($candidature));
        }
    }
}
