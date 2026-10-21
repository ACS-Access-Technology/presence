<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\FilialeScope;
use App\Support\Branding;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Partenaire (client externe) pour le compte duquel ACS organise une rencontre.
 *
 * Son identité visuelle remplace celle de la filiale sur toutes les surfaces de
 * ses rencontres (page d'émargement, QR projeté/imprimé, emails, feuille PDF) —
 * voir {@see Branding::forEvent()}. Enregistré une fois, réutilisé
 * à chaque nouvelle rencontre. Cloisonné par filiale ({@see FilialeScope}).
 *
 * @property int $id
 * @property int $filiale_id
 * @property string $name
 * @property string $logo_path
 * @property ?string $background_path
 * @property string $primary_color
 * @property ?string $secondary_color
 * @property bool $is_institutional
 * @property ?string $contact_name
 * @property ?string $contact_email
 */
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'filiale_id', 'name', 'logo_path', 'background_path', 'primary_color', 'secondary_color', 'is_institutional',
        'contact_name', 'contact_email',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_institutional' => 'boolean'];
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

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
