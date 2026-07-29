<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\SaveRecruitmentReponseRequest;
use App\Http\Requests\Public\StartRecruitmentCandidatureRequest;
use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentTest;
use App\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Test de recrutement en ligne (sans compte). Flux : show() → store()
 * (démarrage) → test() (questions, autosave) → submit() (calcul de score,
 * jamais montré au candidat — voir RecruitmentService::notifyManagers()).
 */
class RecruitmentController extends Controller
{
    public function __construct(private readonly RecruitmentService $recruitment) {}

    public function show(RecruitmentTest $test): View
    {
        abort_unless($test->actif, 404);

        return view('public.recruitment.show', ['test' => $test]);
    }

    public function store(StartRecruitmentCandidatureRequest $request, RecruitmentTest $test): View|RedirectResponse
    {
        abort_unless($test->actif, 404);

        $candidature = $this->recruitment->startCandidature($test, $request->validated());

        if ($candidature === null) {
            return back()->withErrors(['email' => 'Vous avez déjà passé ce test.'])->withInput();
        }

        return redirect()->route('public.recruitment.test', ['test' => $test->slug, 'token' => $candidature->token]);
    }

    public function test(RecruitmentTest $test, string $token): View
    {
        abort_unless($test->actif, 404);

        $candidature = RecruitmentCandidature::where('recruitment_test_id', $test->id)
            ->where('token', $token)->firstOrFail();

        if (! $candidature->estEnCours()) {
            return view('public.recruitment.closed', ['candidature' => $candidature]);
        }

        // Corrigé (`reponse_attendue`) jamais chargé en mémoire côté public : même
        // sans fuite active dans la vue actuelle, une future erreur (dd(), exception,
        // @json($question)) ne doit rien pouvoir exposer du corrigé au candidat.
        $questions = $test->questions()->orderBy('ordre')->get(['id', 'recruitment_test_id', 'ordre', 'contexte']);
        $reponses = $candidature->reponses()->get()->keyBy('recruitment_question_id');

        return view('public.recruitment.test', compact('test', 'candidature', 'questions', 'reponses'));
    }

    public function answer(
        SaveRecruitmentReponseRequest $request,
        RecruitmentTest $test,
        string $token,
        RecruitmentQuestion $question,
    ): JsonResponse {
        abort_unless($question->recruitment_test_id === $test->id, 404);

        $candidature = RecruitmentCandidature::where('recruitment_test_id', $test->id)
            ->where('token', $token)->firstOrFail();

        $reponse = $this->recruitment->saveReponse($candidature, $question, $request->validated());

        if ($reponse === null) {
            return response()->json(['message' => 'Session terminée ou expirée.'], 410);
        }

        return response()->json(['saved' => true]);
    }

    public function submit(RecruitmentTest $test, string $token): View
    {
        $candidature = RecruitmentCandidature::where('recruitment_test_id', $test->id)
            ->where('token', $token)->firstOrFail();

        $result = $this->recruitment->submit($candidature);

        if ($result === null) {
            return view('public.recruitment.closed', ['candidature' => $candidature]);
        }

        return view('public.recruitment.merci');
    }
}
