<?php

declare(strict_types=1);

namespace App\Models;

use App\Http\Controllers\Admin\ReportController;
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

    /**
     * URL AUTHENTIFIÉE du document (route admin scopée par filiale), et non une URL
     * publique : le fichier vit sur le disque privé, servi par
     * {@see ReportController::showDocument()}.
     */
    public function url(): string
    {
        return route('admin.events.report.documents.show', [$this->event_id, $this->id]);
    }
}
