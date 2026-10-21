<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\FilialeScope;
use Database\Factories\RecruitmentTestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Poste de recrutement ouvert au test en ligne, cloisonné par filiale
 * ({@see FilialeScope}, même garantie que {@see EventType}) : no-op hors
 * requête admin (RME-2), 404 sur une autre filiale au route-model binding.
 *
 * @property int $id
 * @property int $filiale_id
 * @property string $titre
 * @property string $slug
 * @property int $duree_minutes
 * @property ?string $instructions
 * @property bool $actif
 */
class RecruitmentTest extends Model
{
    /** @use HasFactory<RecruitmentTestFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['filiale_id', 'titre', 'slug', 'duree_minutes', 'instructions', 'actif'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new FilialeScope);
    }

    /** @return BelongsTo<Filiale, $this> */
    public function filiale(): BelongsTo
    {
        return $this->belongsTo(Filiale::class);
    }

    /** @return HasMany<RecruitmentQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(RecruitmentQuestion::class);
    }

    /** @return HasMany<RecruitmentCandidature, $this> */
    public function candidatures(): HasMany
    {
        return $this->hasMany(RecruitmentCandidature::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
