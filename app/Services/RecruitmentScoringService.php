<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentReponse;

/**
 * Notation pure de la Partie B du test de recrutement : 3 champs comparés au
 * corrigé (`reponse_attendue`) par question, jamais de dépendance à une requête
 * HTTP ou à l'horloge — testable isolément.
 */
final class RecruitmentScoringService
{
    /**
     * @return array{classement: bool, ajustement_tva: bool, retenue_attendue: bool, points: int}
     */
    public function scoreReponse(RecruitmentQuestion $question, RecruitmentReponse $reponse): array
    {
        $attendu = $question->reponse_attendue;
        $tolerance = $question->tolerance;

        $classementOk = $reponse->classement_saisi !== null
            && $reponse->classement_saisi === ($attendu['classement'] ?? null);
        $tvaOk = $this->withinTolerance($reponse->ajustement_tva_saisi, $attendu['ajustement_tva'] ?? null, $tolerance);
        $retenueOk = $this->withinTolerance($reponse->retenue_saisie, $attendu['retenue_attendue'] ?? null, $tolerance);

        return [
            'classement' => $classementOk,
            'ajustement_tva' => $tvaOk,
            'retenue_attendue' => $retenueOk,
            'points' => (int) $classementOk + (int) $tvaOk + (int) $retenueOk,
        ];
    }

    /** Score total d'une candidature = somme des points sur toutes ses réponses. */
    public function scoreCandidature(RecruitmentCandidature $candidature): int
    {
        $total = 0;
        foreach ($candidature->reponses()->with('question')->get() as $reponse) {
            $total += $this->scoreReponse($reponse->question, $reponse)['points'];
        }

        return $total;
    }

    private function withinTolerance(?int $saisi, ?int $attendu, int $tolerance): bool
    {
        if ($saisi === null || $attendu === null) {
            return false;
        }

        return abs($saisi - $attendu) <= $tolerance;
    }
}
