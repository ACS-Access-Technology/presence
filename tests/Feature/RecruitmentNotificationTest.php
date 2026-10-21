<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\RecruitmentCandidatureSubmittedMail;
use App\Models\Filiale;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentTest;
use App\Models\User;
use App\Services\RecruitmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RecruitmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_soumettre_notifie_ladminfiliale_de_la_filiale_et_le_superadmin_mais_pas_une_autre_filiale(): void
    {
        Mail::fake();

        $filialeA = Filiale::factory()->create();
        $filialeB = Filiale::factory()->create();
        $adminA = User::factory()->filialeAdmin()->forFiliale($filialeA)->create();
        $adminB = User::factory()->filialeAdmin()->forFiliale($filialeB)->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $test = RecruitmentTest::factory()->create(['filiale_id' => $filialeA->id]);
        RecruitmentQuestion::factory()->for($test)->create();
        $recruitment = app(RecruitmentService::class);
        $candidature = $recruitment->startCandidature($test, [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'x@exemple.ci', 'telephone' => null,
        ]);

        $recruitment->submit($candidature);

        Mail::assertQueued(RecruitmentCandidatureSubmittedMail::class, fn ($mail) => $mail->hasTo($adminA->email));
        Mail::assertQueued(RecruitmentCandidatureSubmittedMail::class, fn ($mail) => $mail->hasTo($superAdmin->email));
        Mail::assertNotQueued(RecruitmentCandidatureSubmittedMail::class, fn ($mail) => $mail->hasTo($adminB->email));
    }
}
