<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RecruitmentQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Question d'un test de recrutement (une transaction du cas, par exemple).
 * `contexte` = données affichées au candidat. `reponse_attendue` = corrigé,
 * jamais chargé côté contrôleur public.
 *
 * @property int $id
 * @property int $recruitment_test_id
 * @property int $ordre
 * @property array{id: string, entite: string, date: string, piece: string, tiers: string, nature: string, montant_ht: int, tva_comptabilisee: int, retenue_operee: int, informations: string} $contexte
 * @property array{classement: string, ajustement_tva: int, retenue_attendue: int, explication: string} $reponse_attendue
 * @property int $tolerance
 */
class RecruitmentQuestion extends Model
{
    /** @use HasFactory<RecruitmentQuestionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['recruitment_test_id', 'ordre', 'contexte', 'reponse_attendue', 'tolerance'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['contexte' => 'array', 'reponse_attendue' => 'array'];
    }

    /** @return BelongsTo<RecruitmentTest, $this> */
    public function test(): BelongsTo
    {
        return $this->belongsTo(RecruitmentTest::class, 'recruitment_test_id');
    }

    /** Alias for factory ->for() auto-detection (Laravel conventional naming). */
    public function recruitmentTest(): BelongsTo
    {
        return $this->test();
    }
}
