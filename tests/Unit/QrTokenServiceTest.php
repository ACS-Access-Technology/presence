<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Event;
use App\Services\QrTokenService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class QrTokenServiceTest extends TestCase
{
    private QrTokenService $service;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QrTokenService();

        // Événement non persisté : on renseigne juste id + secret utilisés par le service.
        $this->event = new Event();
        $this->event->id = 42;
        $this->event->qr_secret = 'secret-de-test-hmac';
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_current_token_expose_le_temps_restant(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_005)); // multiple de 15

        $result = $this->service->currentToken($this->event);

        $this->assertNotEmpty($result['token']);
        $this->assertSame(QrTokenService::WINDOW_SECONDS, $result['expires_in']);
    }

    public function test_token_courant_est_valide(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_007));
        $token = $this->service->currentToken($this->event)['token'];

        $this->assertTrue($this->service->verifyToken($this->event, $token));
    }

    public function test_token_de_la_fenetre_precedente_reste_accepte(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_000));
        $token = $this->service->currentToken($this->event)['token'];

        // 15 s plus tard : le token appartient à la fenêtre précédente → toléré.
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_016));
        $this->assertTrue($this->service->verifyToken($this->event, $token));
    }

    public function test_token_expire_apres_deux_fenetres(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_000));
        $token = $this->service->currentToken($this->event)['token'];

        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_031)); // 2 fenêtres plus loin
        $this->assertFalse($this->service->verifyToken($this->event, $token));
    }

    public function test_token_bidon_est_rejete(): void
    {
        $this->assertFalse($this->service->verifyToken($this->event, 'nawak'));
    }

    public function test_ticket_de_scan_valide_puis_expire(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(2_000_000));
        $ticket = $this->service->issueScanTicket($this->event);
        $this->assertTrue($this->service->verifyScanTicket($this->event, $ticket));

        // Toujours valide juste avant la limite.
        Carbon::setTestNow(Carbon::createFromTimestamp(2_000_000 + QrTokenService::SCAN_TICKET_TTL));
        $this->assertTrue($this->service->verifyScanTicket($this->event, $ticket));

        // Expiré une seconde après.
        Carbon::setTestNow(Carbon::createFromTimestamp(2_000_001 + QrTokenService::SCAN_TICKET_TTL));
        $this->assertFalse($this->service->verifyScanTicket($this->event, $ticket));
    }

    public function test_ticket_de_scan_lie_a_un_evenement(): void
    {
        $ticket = $this->service->issueScanTicket($this->event);

        $other = new Event();
        $other->id = 99;
        $other->qr_secret = 'secret-de-test-hmac'; // même secret, mais id différent

        $this->assertFalse($this->service->verifyScanTicket($other, $ticket));
    }
}
