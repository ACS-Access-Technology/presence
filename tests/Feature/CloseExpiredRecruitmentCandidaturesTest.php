<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RecruitmentCandidatureStatus;
use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CloseExpiredRecruitmentCandidaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_commande_cloture_les_candidatures_expirees(): void
    {
        $test = RecruitmentTest::factory()->create();
        $question = RecruitmentQuestion::factory()->for($test)->create([
            'reponse_attendue' => ['classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0, 'explication' => ''],
        ]);
        $candidature = RecruitmentCandidature::create([
            'recruitment_test_id' => $test->id, 'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'x@exemple.ci',
            'token' => bin2hex(random_bytes(20)),
            'started_at' => Carbon::now()->subHour(), 'expires_at' => Carbon::now()->subMinute(),
            'statut' => RecruitmentCandidatureStatus::EnCours,
        ]);

        $this->artisan('recruitment:close-expired')->assertSuccessful();

        $this->assertSame(RecruitmentCandidatureStatus::Expire, $candidature->fresh()->statut);
    }

    public function test_la_commande_est_idempotente(): void
    {
        $test = RecruitmentTest::factory()->create();
        RecruitmentCandidature::create([
            'recruitment_test_id' => $test->id, 'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'y@exemple.ci',
            'token' => bin2hex(random_bytes(20)),
            'started_at' => Carbon::now()->subHour(), 'expires_at' => Carbon::now()->subMinute(),
            'statut' => RecruitmentCandidatureStatus::EnCours,
        ]);

        $this->artisan('recruitment:close-expired');
        $this->artisan('recruitment:close-expired')->expectsOutputToContain('0 candidature');
    }
}
