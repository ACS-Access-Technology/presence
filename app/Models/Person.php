<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PersonSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Personne (référentiel unifié, clé = email). Visiteur reconnu par email
 * et/ou membre du Personnel ACS importé (is_staff).
 *
 * @property int $id
 * @property string $email
 * @property string $last_name
 * @property string $first_name
 * @property ?string $phone
 * @property ?string $company
 * @property ?string $direction
 * @property ?string $service
 * @property ?string $position
 * @property bool $is_staff
 * @property PersonSource $source
 */
class Person extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'email', 'last_name', 'first_name', 'phone',
        'company', 'direction', 'service', 'position',
        'is_staff', 'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_staff' => 'boolean',
            'source' => PersonSource::class,
        ];
    }

    /** @return HasMany<Attendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /** @return HasMany<EventInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(EventInvitation::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** Normalise un email pour l'utiliser comme clé de reconnaissance. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
