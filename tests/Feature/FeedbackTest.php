<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventFeedback;
use App\Models\EventType;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function attendance(?Carbon $end = null): Attendance
    {
        $type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
        $event = Event::create([
            'title' => 'Atelier Cybersécurité', 'event_type_id' => $type->id,
            'starts_at' => Carbon::now()->subHours(2), 'ends_at' => $end ?? Carbon::now()->subHour(),
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32), 'public_slug' => 'atelier-'.Str::random(5),
        ]);
        $person = Person::create(['email' => 'awa@acs.ci', 'last_name' => 'Koné', 'first_name' => 'Awa']);

        return Attendance::create([
            'event_id' => $event->id, 'person_id' => $person->id, 'reference' => 'PRS-'.Str::upper(Str::random(6)),
            'last_name' => 'Koné', 'first_name' => 'Awa', 'phone' => '0', 'company' => 'ACS', 'direction' => 'SI', 'position' => 'QA',
            'checked_in_at' => Carbon::now()->subHour(),
        ]);
    }

    public function test_affiche_le_formulaire_apres_la_fin_de_l_evenement(): void
    {
        $attendance = $this->attendance();

        $this->get(route('public.feedback.show', ['attendance' => $attendance->reference]))
            ->assertOk()->assertSee('Votre avis');
    }

    public function test_refuse_avant_la_fin_de_l_evenement(): void
    {
        $attendance = $this->attendance(Carbon::now()->addHour());

        $this->get(route('public.feedback.show', ['attendance' => $attendance->reference]))
            ->assertOk()->assertSee('Pas encore disponible');
    }

    public function test_enregistre_un_avis(): void
    {
        $attendance = $this->attendance();

        $this->post(route('public.feedback.store', ['attendance' => $attendance->reference]), [
            'rating' => 4, 'comment' => 'Très instructif.',
        ])->assertRedirect(route('public.feedback.show', ['attendance' => $attendance->reference]));

        $this->assertDatabaseHas('event_feedbacks', [
            'attendance_id' => $attendance->id, 'rating' => 4, 'comment' => 'Très instructif.',
        ]);
    }

    public function test_un_seul_avis_par_presence(): void
    {
        $attendance = $this->attendance();

        $this->post(route('public.feedback.store', ['attendance' => $attendance->reference]), ['rating' => 5]);
        $this->post(route('public.feedback.store', ['attendance' => $attendance->reference]), ['rating' => 1]);

        $this->assertSame(1, EventFeedback::where('attendance_id', $attendance->id)->count());
        $this->assertSame(5, EventFeedback::where('attendance_id', $attendance->id)->first()->rating);
    }

    public function test_refuse_une_note_hors_bornes(): void
    {
        $attendance = $this->attendance();

        $this->post(route('public.feedback.store', ['attendance' => $attendance->reference]), ['rating' => 7])
            ->assertSessionHasErrors('rating');
    }
}
