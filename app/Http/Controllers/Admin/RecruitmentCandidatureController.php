<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentTest;
use App\Services\RecruitmentScoringService;
use Illuminate\View\View;

/** Liste et détail des candidatures d'un poste de recrutement. */
class RecruitmentCandidatureController extends Controller
{
    public function __construct(private readonly RecruitmentScoringService $scoring) {}

    public function index(RecruitmentTest $test): View
    {
        $candidatures = $test->candidatures()->orderByDesc('created_at')->get();

        return view('admin.recruitment.candidatures.index', [
            'test' => $test, 'candidatures' => $candidatures, 'nav' => 'recrutement',
        ]);
    }

    public function show(RecruitmentTest $test, RecruitmentCandidature $candidature): View
    {
        $questions = $test->questions()->orderBy('ordre')->get();
        $reponses = $candidature->reponses()->get()->keyBy('recruitment_question_id');

        $detail = $questions->map(function ($question) use ($reponses) {
            $reponse = $reponses->get($question->id);

            return [
                'question' => $question,
                'reponse' => $reponse,
                'resultat' => $reponse !== null ? $this->scoring->scoreReponse($question, $reponse) : null,
            ];
        });

        return view('admin.recruitment.candidatures.show', [
            'test' => $test, 'candidature' => $candidature, 'detail' => $detail, 'nav' => 'recrutement',
        ]);
    }
}
