<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RecruitmentCandidatureStatus;
use App\Mail\RecruitmentCandidatureSubmittedMail;
use App\Models\RecruitmentTest;
use App\Services\RecruitmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecruitmentCandidatureSubmittedMailTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{RecruitmentCandidatureStatus, string, string}> */
    public static function issues(): array
    {
        return [
            'soumise' => [RecruitmentCandidatureStatus::Soumis, 'Candidature reçue', "Une candidature vient d'être soumise"],
            'expirée' => [RecruitmentCandidatureStatus::Expire, 'Candidature expirée', 'clôturée automatiquement'],
            'abandonnée' => [RecruitmentCandidatureStatus::Abandonnee, 'Candidature abandonnée', 'a été abandonnée'],
        ];
    }

    #[DataProvider('issues')]
    public function test_sujet_et_phrase_propres_a_chaque_issue(RecruitmentCandidatureStatus $statut, string $sujet, string $phrase): void
    {
        $test = RecruitmentTest::factory()->create(['titre' => 'Fiscaliste']);
        $candidature = app(RecruitmentService::class)->startCandidature($test, [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'jane@exemple.ci', 'telephone' => null,
        ]);
        $candidature->forceFill(['statut' => $statut, 'score' => 0])->save();

        $mail = new RecruitmentCandidatureSubmittedMail($candidature);

        $mail->assertHasSubject($sujet.' — Fiscaliste');
        $mail->assertSeeInHtml($phrase);
    }
}
