<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Présence (une par personne et par événement).
 *
 * @property int $id
 * @property int $event_id
 * @property int $person_id
 * @property string $last_name
 * @property string $first_name
 * @property ?string $phone
 * @property ?string $company
 * @property ?string $direction
 * @property ?string $service
 * @property ?string $position
 * @property ?string $signature_path
 * @property ?string $latitude
 * @property ?string $longitude
 * @property ?string $accuracy
 * @property bool $is_manual
 * @property bool $manual_confirmed
 * @property ?int $recorded_by
 * @property Carbon $checked_in_at
 * @property ?Carbon $departed_at
 * @property string $reference
 * @property ?Carbon $confirmation_email_sent_at
 */
class Attendance extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'event_id', 'person_id',
        'last_name', 'first_name', 'phone', 'company', 'direction', 'service', 'position',
        'signature_path', 'latitude', 'longitude', 'accuracy',
        'is_manual', 'manual_confirmed', 'recorded_by',
        'checked_in_at', 'departed_at', 'reference', 'confirmation_email_sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_manual' => 'boolean',
            'manual_confirmed' => 'boolean',
            'checked_in_at' => 'datetime',
            'departed_at' => 'datetime',
            'confirmation_email_sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Personne encore présente (aucun départ enregistré). */
    public function isActive(): bool
    {
        return $this->departed_at === null;
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
