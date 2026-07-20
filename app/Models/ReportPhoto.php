<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Photo de l'activité (galerie compte-rendu + portfolio).
 *
 * @property int $id
 * @property int $event_id
 * @property string $path
 * @property ?string $caption
 * @property int $position
 */
class ReportPhoto extends Model
{
    /** @var list<string> */
    protected $fillable = ['event_id', 'path', 'caption', 'position', 'uploaded_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** URL publique de la photo (via le lien symbolique storage). */
    public function url(): string
    {
        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->path);
    }
}
