<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use App\Services\Attendance\AttendanceInput;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private EventType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = User::factory()->create();
        $this->type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
    }

    private function liveEvent(): Event
    {
        return Event::create([
            'title' => 'Atelier Cybersécurité', 'event_type_id' => $this->type->id,
            'starts_at' => Carbon::now()->subHour(), 'ends_at' => Carbon::now()->addHour(),
            'location' => 'Salle Ébène', 'qr_mode' => QrMode::Tournant->value,
            'qr_secret' => Str::random(32), 'public_slug' => 'atelier-cyber',
        ]);
    }

    private function attend(Event $e, string $email, string $first, string $last): Attendance
    {
        return app(AttendanceService::class)->register($e, new AttendanceInput(
            email: $email, lastName: $last, firstName: $first,
            phone: '+225 07', company: 'ACS', direction: 'SI', position: 'Analyste',
        ));
    }

    public function test_liste_requiert_authentification(): void
    {
        $this->get(route('admin.events.index'))->assertRedirect(route('login'));
    }

    public function test_liste_affiche_les_evenements(): void
    {
        $this->liveEvent();

        $this->actingAs($this->user)->get(route('admin.events.index'))
            ->assertOk()->assertSee('Atelier Cybersécurité');
    }

    public function test_detail_affiche_la_structure_et_injecte_les_presences(): void
    {
        $event = $this->liveEvent();
        $this->attend($event, 'awa@acs.ci', 'Awa', 'Koné');

        // Le tableau est rendu côté client depuis window.EVENT.rows (fidèle au proto) :
        // on vérifie la structure SSR + l'injection des données (le contenu détaillé
        // est couvert par le test du feed JSON).
        $this->actingAs($this->user)->get(route('admin.events.show', $event))
            ->assertOk()
            ->assertSee('Liste de présence')
            ->assertSee('window.EVENT', false)
            ->assertSee('awa@acs.ci');
    }

    public function test_feed_json(): void
    {
        $event = $this->liveEvent();
        $this->attend($event, 'awa@acs.ci', 'Awa', 'Koné');

        $this->actingAs($this->user)->getJson(route('admin.events.attendances.feed', $event))
            ->assertOk()
            ->assertJsonPath('stats.total', 1)
            ->assertJsonPath('rows.0.name', 'Awa Koné');
    }

    public function test_export_csv(): void
    {
        $event = $this->liveEvent();
        $this->attend($event, 'awa@acs.ci', 'Awa', 'Koné');

        $response = $this->actingAs($this->user)->get(route('admin.events.attendances.export', $event));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content); // BOM
        $this->assertStringContainsString('Koné;Awa;awa@acs.ci', $content);
    }

    public function test_export_csv_respecte_le_filtre_de_statut(): void
    {
        $event = $this->liveEvent();
        $this->attend($event, 'awa@acs.ci', 'Awa', 'Koné');
        $recurrent = $this->attend($event, 'ancien@acs.ci', 'Awa', 'Ancienne');
        // Rend "Ancienne" récurrente : présence antérieure sur un autre événement.
        $past = Event::create([
            'title' => 'Passé', 'event_type_id' => $this->type->id,
            'starts_at' => Carbon::now()->subDays(2), 'ends_at' => Carbon::now()->subDays(2)->addHour(),
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32), 'public_slug' => 'passe',
        ]);
        $this->attend($past, 'ancien@acs.ci', 'Awa', 'Ancienne');

        $content = $this->actingAs($this->user)
            ->get(route('admin.events.attendances.export', $event).'?chip=new')
            ->streamedContent();

        $this->assertStringContainsString('Koné;Awa;awa@acs.ci', $content);
        $this->assertStringNotContainsString('Ancienne', $content);
    }

    public function test_export_xlsx(): void
    {
        $event = $this->liveEvent();
        $this->attend($event, 'awa@acs.ci', 'Awa', 'Koné');

        $response = $this->actingAs($this->user)->get(route('admin.events.attendances.export.xlsx', $event));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
    }

    public function test_export_pdf(): void
    {
        $event = $this->liveEvent();
        $this->attend($event, 'awa@acs.ci', 'Awa', 'Koné');

        $response = $this->actingAs($this->user)->get(route('admin.events.attendances.export.pdf', $event));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_export_xlsx_incorpore_la_signature_et_formate_le_telephone_en_texte(): void
    {
        $event = $this->liveEvent();
        $attendance = $this->attend($event, 'awa@acs.ci', 'Awa', 'Koné');
        $attendance->forceFill(['signature_path' => 'signatures/'.$event->id.'/test.png'])->save();
        Storage::disk('local')->put('signatures/'.$event->id.'/test.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        ));

        $path = $this->actingAs($this->user)
            ->get(route('admin.events.attendances.export.xlsx', $event))
            ->getFile()->getRealPath();

        $zip = new \ZipArchive();
        $zip->open($path);
        $hasMedia = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with($zip->getNameIndex($i), 'xl/media/')) {
                $hasMedia = true;
            }
        }
        $zip->close();
        $this->assertTrue($hasMedia, 'La signature devrait être incorporée en image dans le XLSX.');

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $phoneCell = $spreadsheet->getActiveSheet()->getCell('D2');
        $this->assertSame(
            \PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT,
            $phoneCell->getStyle()->getNumberFormat()->getFormatCode(),
        );
    }

    public function test_marquer_et_annuler_un_depart(): void
    {
        $event = $this->liveEvent();
        $a = $this->attend($event, 'awa@acs.ci', 'Awa', 'Koné');

        $this->actingAs($this->user)
            ->postJson(route('admin.events.attendances.departure', [$event, $a]))
            ->assertOk()->assertJson(fn ($j) => $j->whereNot('left', null));
        $this->assertNotNull($a->refresh()->departed_at);

        $this->actingAs($this->user)
            ->postJson(route('admin.events.attendances.undo-departure', [$event, $a]))
            ->assertOk()->assertJsonPath('left', null);
        $this->assertNull($a->refresh()->departed_at);
    }

    public function test_presence_manuelle(): void
    {
        $event = $this->liveEvent();

        $this->actingAs($this->user)->postJson(route('admin.events.attendances.manual', $event), [
            'last_name' => 'Bamba', 'first_name' => 'Aya', 'company' => 'MTN CI',
            'direction' => 'Commercial', 'position' => 'KAM', 'manual_confirmed' => '1',
        ])->assertStatus(201);

        $this->assertDatabaseHas('attendances', ['event_id' => $event->id, 'is_manual' => true, 'last_name' => 'Bamba']);
    }

    public function test_presence_manuelle_exige_confirmation(): void
    {
        $event = $this->liveEvent();

        $this->actingAs($this->user)->postJson(route('admin.events.attendances.manual', $event), [
            'last_name' => 'Bamba', 'first_name' => 'Aya', 'company' => 'MTN',
            'direction' => 'Com', 'position' => 'KAM',
        ])->assertStatus(422)->assertJsonValidationErrors('manual_confirmed');
    }

    public function test_signature_privee_accessible_authentifie(): void
    {
        $event = $this->liveEvent();
        $a = $this->attend($event, 'awa@acs.ci', 'Awa', 'Koné');
        $a->update(['signature_path' => 'signatures/test.png']);
        Storage::disk('local')->put('signatures/test.png', 'PNGDATA');

        $this->actingAs($this->user)->get(route('admin.events.attendances.signature', [$event, $a]))
            ->assertOk();

        // Une présence manuelle sans signature → 404.
        $manual = app(AttendanceService::class)->register($event, new AttendanceInput(
            email: 'x@x.ci', lastName: 'X', firstName: 'Y', isManual: true, manualConfirmed: true,
        ));
        $this->actingAs($this->user)->get(route('admin.events.attendances.signature', [$event, $manual]))
            ->assertNotFound();
    }

    public function test_attendance_scopee_a_son_evenement(): void
    {
        $event = $this->liveEvent();
        $other = Event::create([
            'title' => 'Autre', 'event_type_id' => $this->type->id,
            'starts_at' => Carbon::now()->subHour(), 'ends_at' => Carbon::now()->addHour(),
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32), 'public_slug' => 'autre',
        ]);
        $a = $this->attend($event, 'awa@acs.ci', 'Awa', 'Koné');

        // L'attendance de $event ne doit pas être joignable via $other.
        $this->actingAs($this->user)
            ->postJson(route('admin.events.attendances.departure', [$other, $a]))
            ->assertNotFound();
    }
}
