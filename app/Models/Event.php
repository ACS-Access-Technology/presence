<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\QrMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Événement ACS Groupe.
 *
 * @property int $id
 * @property string $title
 * @property int $event_type_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property ?string $location
 * @property QrMode $qr_mode
 * @property ?string $qr_secret
 * @property string $public_slug
 * @property ?Carbon $closed_at
 * @property ?Carbon $report_email_sent_at
 * @property ?Carbon $cancelled_at
 * @property ?string $cancellation_reason
 */
class Event extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'title', 'event_type_id', 'starts_at', 'ends_at', 'location',
        'qr_mode', 'qr_secret', 'public_slug',
        'closed_at', 'report_email_sent_at', 'cancelled_at', 'cancellation_reason',
        'created_by',
    ];

    /** @var list<string> */
    protected $hidden = ['qr_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'closed_at' => 'datetime',
            'report_email_sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'qr_mode' => QrMode::class,
        ];
    }

    /** @return BelongsTo<EventType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(EventType::class, 'event_type_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    /** @return HasMany<EventReschedule, $this> */
    public function reschedules(): HasMany
    {
        return $this->hasMany(EventReschedule::class);
    }

    /** @return HasOne<EventReport, $this> */
    public function report(): HasOne
    {
        return $this->hasOne(EventReport::class);
    }

    /** @return HasMany<ReportDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ReportDocument::class);
    }

    /** @return HasMany<ReportPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(ReportPhoto::class);
    }

    // ---------------------------------------------------------------------
    // Statut dérivé (jamais stocké) : annulé › clos › en cours › à venir.
    // ---------------------------------------------------------------------

    public function status(?Carbon $now = null): EventStatus
    {
        $now ??= Carbon::now();

        if ($this->cancelled_at !== null) {
            return EventStatus::Annule;
        }
        if ($this->closed_at !== null || $now->greaterThan($this->ends_at)) {
            return EventStatus::Clos;
        }
        if ($now->greaterThanOrEqualTo($this->starts_at)) {
            return EventStatus::EnCours;
        }

        return EventStatus::AVenir;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** L'événement a commencé (le compte-rendu devient éditable), hors annulation. */
    public function hasStarted(?Carbon $now = null): bool
    {
        return ! $this->isCancelled() && ($now ?? Carbon::now())->greaterThanOrEqualTo($this->starts_at);
    }

    /** L'émargement est ouvert seulement pendant la fenêtre, hors annulation/clôture. */
    public function isOpenForCheckIn(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return ! $this->isCancelled()
            && $this->closed_at === null
            && $now->betweenIncluded($this->starts_at, $this->ends_at);
    }

    /** Le mode QR se verrouille dès qu'une présence existe. */
    public function isQrModeLocked(): bool
    {
        return $this->attendances()->exists();
    }
}
