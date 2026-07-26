<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreFeedbackRequest;
use App\Models\Attendance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Avis post-événement, accessible via la référence de présence (envoyée dans
 * l'email de confirmation). Un seul avis par présence (contrainte unique DB).
 *
 * La référence est une clé d'accès non authentifiée : elle expose l'identité de la
 * présence et permet de déposer l'unique avis. Limitation de débit PAR IP posée en
 * middleware de route (`throttle:...`, voir routes/web.php) — jamais dans le corps
 * du contrôleur, qui s'exécute APRÈS la résolution du binding `{attendance:reference}`
 * et ne throttlerait donc jamais les références invalides (le cas qu'on veut freiner
 * pour de vrai contre l'énumération). Complète l'entropie de la référence (voir
 * AttendanceService::REFERENCE_LENGTH).
 */
class FeedbackController extends Controller
{
    public function show(Attendance $attendance): View
    {
        $attendance->load('event', 'feedback');

        return view('public.feedback', [
            'event' => $attendance->event,
            'attendance' => $attendance,
            'available' => Carbon::now()->greaterThanOrEqualTo($attendance->event->ends_at),
        ]);
    }

    public function store(StoreFeedbackRequest $request, Attendance $attendance): RedirectResponse
    {
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
}
