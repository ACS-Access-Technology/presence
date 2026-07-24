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
