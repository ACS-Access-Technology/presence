<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RecruitmentTest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Liste des postes de recrutement (scopée filiale) + activation/désactivation. */
class RecruitmentTestController extends Controller
{
    public function index(): View
    {
        $tests = RecruitmentTest::withCount('candidatures')->orderBy('titre')->get();

        return view('admin.recruitment.tests.index', ['tests' => $tests, 'nav' => 'recrutement']);
    }

    public function toggleActif(RecruitmentTest $test): RedirectResponse
    {
        $test->update(['actif' => ! $test->actif]);

        return back()->with('status', $test->actif ? 'Poste réactivé.' : 'Poste désactivé.');
    }
}
