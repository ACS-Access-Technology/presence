<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreFeedbackRequest;
use App\Models\Attendance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Avis post-événement, accessible via la référence de présence (envoyée dans
 * l'email de confirmation). Un seul avis par présence (contrainte unique DB).
 *
 * La référence est une clé d'accès non authentifiée : elle expose l'identité de la
 * présence et permet de déposer l'unique avis. On la limite en débit PAR IP (jamais
 * par IP+référence, qui offrirait un compteur distinct à chaque référence testée et
 * annulerait la protection) pour freiner l'énumération par brute-force, en complément
 * de l'entropie relevée de la référence (voir AttendanceService::REFERENCE_LENGTH).
 */
class FeedbackController extends Controller
{
    /** Consultations d'avis tolérées par IP et par minute (usage légitime = 1 lien). */
    private const int SHOW_MAX_PER_MINUTE = 30;

    /** Dépôts d'avis tolérés par IP et par minute. */
    private const int STORE_MAX_PER_MINUTE = 10;

    public function show(Request $request, Attendance $attendance): View
    {
        $this->throttle('feedback-show:'.$request->ip(), self::SHOW_MAX_PER_MINUTE);

        $attendance->load('event', 'feedback');

        return view('public.feedback', [
            'event' => $attendance->event,
            'attendance' => $attendance,
            'available' => Carbon::now()->greaterThanOrEqualTo($attendance->event->ends_at),
        ]);
    }

    public function store(StoreFeedbackRequest $request, Attendance $attendance): RedirectResponse
    {
        $this->throttle('feedback-store:'.$request->ip(), self::STORE_MAX_PER_MINUTE);

        abort_unless(Carbon::now()->greaterThanOrEqualTo($attendance->event->ends_at), 422, "L'événement n'est pas encore terminé.");

        if ($attendance->feedback === null) {
            $attendance->feedback()->create([
                'event_id' => $attendance->event_id,
                'rating' => $request->integer('rating'),
                'comment' => $request->filled('comment') ? (string) $request->string('comment') : null,
            ]);
        }

        return redirect()->route('public.feedback.show', ['attendance' => $attendance->reference]);
    }

    /**
     * Limitation de débit par clé (IP) : au-delà du seuil, coupe court en 429 avant
     * toute résolution de référence, pour rendre l'énumération non rentable.
     */
    private function throttle(string $key, int $maxPerMinute): void
    {
        abort_if(RateLimiter::tooManyAttempts($key, $maxPerMinute), 429, 'Trop de tentatives. Réessayez dans une minute.');
        RateLimiter::hit($key, 60);
    }
}
