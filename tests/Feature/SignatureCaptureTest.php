<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Models\Event;
use App\Models\EventType;
use App\Services\QrTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Robustesse de la captation des signatures côté serveur.
 */
class SignatureCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const string INK_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private function event(): Event
    {
        $type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);

        return Event::create([
            'title' => 'Atelier', 'event_type_id' => $type->id,
            'starts_at' => Carbon::now()->subHour(), 'ends_at' => Carbon::now()->addHour(),
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32), 'public_slug' => 'ev-'.Str::random(8),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Event $event, string $signature): array
    {
        return [
            'email' => 'awa@exemple.ci', 'last_name' => 'Koné', 'first_name' => 'Awa', 'phone' => '0700000000',
            'company' => 'ACS', 'direction' => 'SI', 'position' => 'Analyste',
            'latitude' => 5.35, 'longitude' => -4.01,
            'signature' => $signature,
            'ticket' => app(QrTokenService::class)->issueScanTicket($event),
            'consent' => '1',
        ];
    }

    public function test_aucun_fichier_signature_orphelin_si_l_enregistrement_echoue(): void
    {
        Storage::fake('local');
        $event = $this->event();
        // Panne réelle à l'insertion : la table des présences a disparu.
        Schema::drop('attendances');

        $this->postJson(route('public.attendance.store', ['event' => $event->public_slug]), $this->payload($event, 'data:image/png;base64,'.self::INK_PNG))
            ->assertServerError();

        $this->assertSame([], Storage::disk('local')->allFiles('signatures'));
    }

    public function test_une_signature_vide_ou_corrompue_est_refusee_sans_rien_ecrire(): void
    {
        Storage::fake('local');
        $event = $this->event();

        $blank = imagecreatetruecolor(300, 120);
        imagesavealpha($blank, true);
        imagefill($blank, 0, 0, imagecolorallocatealpha($blank, 0, 0, 0, 127));
        ob_start();
        imagepng($blank);
        $blankPng = base64_encode((string) ob_get_clean());

        foreach (['data:image/png;base64,'.$blankPng, 'data:image/png;base64,PASUNPNG', 'data:,', ''] as $signature) {
            $this->postJson(route('public.attendance.store', ['event' => $event->public_slug]), $this->payload($event, $signature))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('signature');
        }

        $this->assertSame(0, $event->attendances()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('signatures'));
    }
}
