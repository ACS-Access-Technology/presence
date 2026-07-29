<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecruitmentCandidatureStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tentative d'un candidat sur un test de recrutement (identité déclarée, pas
 * de compte). `token` : clé d'URL opaque, jamais un identifiant de session.
 *
 * @property int $id
 * @property int $recruitment_test_id
 * @property string $nom
 * @property string $prenom
 * @property string $email
 * @property ?string $telephone
 * @property string $token
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property ?Carbon $submitted_at
 * @property RecruitmentCandidatureStatus $statut
 * @property ?int $score
 */
class RecruitmentCandidature extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'recruitment_test_id', 'nom', 'prenom', 'email', 'telephone',
        'token', 'started_at', 'expires_at', 'submitted_at', 'statut', 'score',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'statut' => RecruitmentCandidatureStatus::class,
        ];
    }

    /** @return BelongsTo<RecruitmentTest, $this> */
    public function test(): BelongsTo
    {
        return $this->belongsTo(RecruitmentTest::class, 'recruitment_test_id');
    }

    /** @return HasMany<RecruitmentReponse, $this> */
    public function reponses(): HasMany
    {
        return $this->hasMany(RecruitmentReponse::class);
    }

    /** Encore active : ni terminée, ni hors délai (le serveur fait foi, jamais le client). */
    public function estEnCours(): bool
    {
        return $this->statut === RecruitmentCandidatureStatus::EnCours
            && Carbon::now()->lessThan($this->expires_at);
    }
}
