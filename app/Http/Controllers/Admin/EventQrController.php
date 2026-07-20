<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\QrMode;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\QrImageService;
use App\Services\QrTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Diffusion du QR d'émargement côté organisateur.
 *
 * - Statique : URL stable, QR imprimable.
 * - Tournant : token renouvelé toutes les 15 s ; l'écran de projection poll
 *   `current` et régénère le QR (polling, sans websocket).
 *
 * NOTE : l'habillage fidèle de l'écran de projection (dashboard.html) sera
 * finalisé ultérieurement ; cette version est fonctionnelle et testable.
 */
class EventQrController extends Controller
{
    public function __construct(private readonly QrImageService $images) {}

    /** Token courant + SVG, consommé par le polling de la page de projection. */
    public function current(Event $event, QrTokenService $tokens): JsonResponse
    {
        $url = $this->attendanceUrl($event);
        $expiresIn = null;

        if ($event->qr_mode === QrMode::Tournant) {
            $current = $tokens->currentToken($event);
            $url .= '?t='.$current['token'];
            $expiresIn = $current['expires_in'];
        }

        return response()->json([
            'mode' => $event->qr_mode->value,
            'expires_in' => $expiresIn,
            'svg' => $this->images->svgDataUri($url),
        ]);
    }

    /** Écran de projection (QR renouvelé en boucle pour le mode tournant). */
    public function projection(Event $event): View
    {
        return view('admin.events.projection', ['event' => $event]);
    }

    /** Vue imprimable du QR statique. */
    public function print(Event $event, QrTokenService $tokens): View
    {
        abort_if($event->qr_mode !== QrMode::Statique, 404);

        return view('admin.events.qr-print', [
            'event' => $event,
            'svg' => $this->images->svgDataUri($this->attendanceUrl($event), 420),
        ]);
    }

    /** URL publique stable d'émargement de l'événement. */
    private function attendanceUrl(Event $event): string
    {
        return route('public.attendance.show', ['event' => $event->public_slug]);
    }
}
