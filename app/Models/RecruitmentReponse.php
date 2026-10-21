<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\RecruitmentScoringService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réponse d'un candidat à une question (3 champs saisis), comparée au corrigé
 * de la question par {@see RecruitmentScoringService}.
 *
 * @property int $id
 * @property int $recruitment_candidature_id
 * @property int $recruitment_question_id
 * @property ?string $classement_saisi
 * @property ?int $ajustement_tva_saisi
 * @property ?int $retenue_saisie
 */
class RecruitmentReponse extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'recruitment_candidature_id', 'recruitment_question_id',
        'classement_saisi', 'ajustement_tva_saisi', 'retenue_saisie',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['ajustement_tva_saisi' => 'integer', 'retenue_saisie' => 'integer'];
    }

    /** @return BelongsTo<RecruitmentQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(RecruitmentQuestion::class, 'recruitment_question_id');
    }

    /** @return BelongsTo<RecruitmentCandidature, $this> */
    public function candidature(): BelongsTo
    {
        return $this->belongsTo(RecruitmentCandidature::class, 'recruitment_candidature_id');
    }
}
