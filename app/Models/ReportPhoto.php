<?php

declare(strict_types=1);

namespace App\Models;

use App\Http\Controllers\Admin\ReportController;
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

    /**
     * URL AUTHENTIFIÉE de la photo (route admin scopée par filiale), et non une URL
     * publique : le fichier vit sur le disque privé, servi par
     * {@see ReportController::showPhoto()}.
     */
    public function url(): string
    {
        return route('admin.events.report.photos.show', [$this->event_id, $this->id]);
    }
}
