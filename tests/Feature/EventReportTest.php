<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private EventType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->user = User::factory()->create();
        $this->type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
    }

    private function event(bool $started = true): Event
    {
        return Event::create([
            'title' => 'Atelier', 'event_type_id' => $this->type->id,
            'starts_at' => $started ? Carbon::now()->subHour() : Carbon::now()->addDay(),
            'ends_at' => $started ? Carbon::now()->addHour() : Carbon::now()->addDay()->addHour(),
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32),
            'public_slug' => 'atelier-'.Str::random(5),
        ]);
    }

    public function test_enregistre_le_texte(): void
    {
        $event = $this->event();

        $this->actingAs($this->user)->postJson(route('admin.events.report.save', $event), [
            'body' => "## Bilan\n\n- Objectif atteint",
        ])->assertOk();

        $this->assertDatabaseHas('event_reports', ['event_id' => $event->id]);
        $this->assertStringContainsString('Bilan', $event->report()->first()->body);
    }

    public function test_texte_bloque_avant_le_debut(): void
    {
        $event = $this->event(started: false);

        $this->actingAs($this->user)->postJson(route('admin.events.report.save', $event), ['body' => 'x'])
            ->assertStatus(422);
        $this->assertDatabaseCount('event_reports', 0);
    }

    public function test_upload_document(): void
    {
        $event = $this->event();

        $response = $this->actingAs($this->user)->postJson(route('admin.events.report.documents.store', $event), [
            'files' => [UploadedFile::fake()->create('bilan.pdf', 200, 'application/pdf')],
        ])->assertStatus(201);

        $this->assertDatabaseHas('report_documents', ['event_id' => $event->id, 'original_name' => 'bilan.pdf']);
        $path = $event->documents()->first()->path;
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($response->json('documents.0.delete_url'));
    }

    public function test_upload_document_refuse_mauvais_type(): void
    {
        $event = $this->event();

        $this->actingAs($this->user)->postJson(route('admin.events.report.documents.store', $event), [
            'files' => [UploadedFile::fake()->create('malware.exe', 10)],
        ])->assertStatus(422);
    }

    public function test_upload_et_suppression_photo(): void
    {
        $event = $this->event();

        $resp = $this->actingAs($this->user)->postJson(route('admin.events.report.photos.store', $event), [
            'files' => [UploadedFile::fake()->image('activite.jpg', 800, 600)],
        ])->assertStatus(201);

        $photo = $event->photos()->firstOrFail();
        Storage::disk('public')->assertExists($photo->path);

        $this->actingAs($this->user)
            ->deleteJson(route('admin.events.report.photos.destroy', [$event, $photo]))
            ->assertOk();
        $this->assertDatabaseMissing('report_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertMissing($photo->path);
    }

    public function test_media_scope_a_son_evenement(): void
    {
        $event = $this->event();
        $other = $this->event();
        $resp = $this->actingAs($this->user)->postJson(route('admin.events.report.documents.store', $event), [
            'files' => [UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf')],
        ]);
        $docId = $resp->json('documents.0.id');

        // Suppression via un autre événement → 404 (scopeBindings).
        $this->actingAs($this->user)
            ->deleteJson(route('admin.events.report.documents.destroy', [$other, $docId]))
            ->assertNotFound();
    }
}
