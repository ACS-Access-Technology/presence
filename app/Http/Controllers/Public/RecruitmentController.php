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
use App\Support\Branding;
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

        return view('public.recruitment.show', ['test' => $test, 'branding' => Branding::forFiliale($test->filiale_id)]);
    }

    public function store(StartRecruitmentCandidatureRequest $request, RecruitmentTest $test): View|RedirectResponse
    {
        abort_unless($test->actif, 404);

        $identite = $request->validated();
        $candidature = $this->recruitment->startCandidature($test, $identite);

        // Clé de session liée au poste ET à l'email : un double POST (double clic,
        // réseau lent) renvoie le navigateur vers le lien de la candidature créée
        // par le premier POST. Jamais une clé « poste seul » : sur un poste
        // partagé, un 2e candidat récupérerait le jeton du premier.
        $sessionKey = 'recrutement.'.$test->id.'.'.hash('sha256', RecruitmentService::normalizeEmail($identite['email']));

        if ($candidature === null) {
            $token = $request->session()->get($sessionKey);
            if (is_string($token) && $token !== '') {
                return redirect()->route('public.recruitment.test', ['test' => $test->slug, 'token' => $token]);
            }

            return back()->withErrors(['email' => 'Vous avez déjà passé ce test.'])->withInput();
        }

        $request->session()->put($sessionKey, $candidature->token);

        return redirect()->route('public.recruitment.test', ['test' => $test->slug, 'token' => $candidature->token]);
    }

    public function test(RecruitmentTest $test, string $token): View
    {
        abort_unless($test->actif, 404);

        $candidature = RecruitmentCandidature::where('recruitment_test_id', $test->id)
            ->where('token', $token)->firstOrFail();

        $branding = Branding::forFiliale($test->filiale_id);

        if (! $this->recruitment->registerPageOpen($candidature)) {
            return view('public.recruitment.closed', ['candidature' => $candidature->fresh(), 'branding' => $branding]);
        }

        // Corrigé (`reponse_attendue`) jamais chargé en mémoire côté public : même
        // sans fuite active dans la vue actuelle, une future erreur (dd(), exception,
        // @json($question)) ne doit rien pouvoir exposer du corrigé au candidat.
        $questions = $test->questions()->orderBy('ordre')->get(['id', 'recruitment_test_id', 'ordre', 'contexte']);
        $reponses = $candidature->reponses()->get()->keyBy('recruitment_question_id');

        return view('public.recruitment.test', compact('test', 'candidature', 'questions', 'reponses', 'branding'));
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
        $branding = Branding::forFiliale($test->filiale_id);

        if ($result === null) {
            return view('public.recruitment.closed', ['candidature' => $candidature, 'branding' => $branding]);
        }

        return view('public.recruitment.merci', ['branding' => $branding]);
    }
}
