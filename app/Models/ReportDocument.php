<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document joint au compte-rendu.
 *
 * @property int $id
 * @property int $event_id
 * @property string $original_name
 * @property string $path
 * @property ?string $mime
 * @property ?int $size
 * @property ?int $uploaded_by
 */
class ReportDocument extends Model
{
    /** @var list<string> */
    protected $fillable = ['event_id', 'original_name', 'path', 'mime', 'size', 'uploaded_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** URL publique du document (via le lien symbolique storage). */
    public function url(): string
    {
        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->path);
    }
}
