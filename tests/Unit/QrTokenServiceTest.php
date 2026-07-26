<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Event;
use App\Services\QrTokenService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QrTokenServiceTest extends TestCase
{
    private QrTokenService $service;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QrTokenService;

        // Événement non persisté : on renseigne juste id + secret utilisés par le service.
        $this->event = new Event;
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

    /**
     * Non-régression : découvert en test de charge (voir
     * docs/gestion-projet/test-de-charge.md). Le payload du ticket était
     * `event_id|seconde`, déterministe — deux visiteurs scannant le même
     * événement dans la même seconde recevaient un ticket IDENTIQUE, que
     * l'anti-rejeu par ticket (`attendance-store-ticket`, 5 usages max)
     * confondait avec un seul ticket rejoué, bloquant à tort le 6e visiteur
     * légitime d'une même seconde.
     */
    public function test_deux_tickets_emis_la_meme_seconde_sont_differents(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(2_000_000));

        $tickets = array_map(fn () => $this->service->issueScanTicket($this->event), range(1, 20));

        $this->assertCount(20, array_unique($tickets), 'Chaque émission doit produire un ticket unique, même à la même seconde.');
        foreach ($tickets as $ticket) {
            $this->assertTrue($this->service->verifyScanTicket($this->event, $ticket));
        }
    }

    public function test_ticket_de_scan_lie_a_un_evenement(): void
    {
        $ticket = $this->service->issueScanTicket($this->event);

        $other = new Event;
        $other->id = 99;
        $other->qr_secret = 'secret-de-test-hmac'; // même secret, mais id différent

        $this->assertFalse($this->service->verifyScanTicket($other, $ticket));
    }

    // =====================================================================
    // Anti-rejeu du token tournant : borne EXACTE de rotation + asymétrie
    // =====================================================================

    /**
     * Borne exacte : un token reste valide jusqu'à la DERNIÈRE seconde de la
     * fenêtre suivante (tolérance « précédente ») puis est rejeté dès la première
     * seconde de la fenêtre N+2. Choix d'horodatages alignés sur un multiple de 15
     * pour tester la milliseconde près sans dépendre d'une fenêtre partielle.
     */
    public function test_borne_exacte_de_rotation_a_la_seconde_pres(): void
    {
        // 1_000_005 = 66667 × 15 : début pile de la fenêtre 66667.
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_005));
        $token = $this->service->currentToken($this->event)['token'];

        // Dernière seconde de la fenêtre suivante (66668) → encore « précédente ».
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_034));
        $this->assertTrue(
            $this->service->verifyToken($this->event, $token),
            'Le token doit rester accepté jusqu\'à la dernière seconde de la fenêtre suivante.'
        );

        // Première seconde de la fenêtre N+2 (66669) → hors {courante, précédente}.
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_035));
        $this->assertFalse(
            $this->service->verifyToken($this->event, $token),
            'Le token doit être rejeté dès l\'entrée dans la fenêtre N+2.'
        );
    }

    /**
     * Asymétrie anti-rejeu : SEULES la fenêtre courante et la précédente sont
     * acceptées, JAMAIS une fenêtre future. Un token pré-calculé (ou issu d'un
     * scanner dont l'horloge est en avance) ne doit pas être rejouable « en avant ».
     */
    public function test_token_dune_fenetre_future_est_rejete(): void
    {
        // On capture le token de la fenêtre 66668 (le futur relatif à 66667).
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_020)); // fenêtre 66668
        $tokenFutur = $this->service->currentToken($this->event)['token'];

        // On revient dans la fenêtre 66667 : le token « futur » n'est ni la fenêtre
        // courante ni la précédente → refus.
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_005)); // fenêtre 66667
        $this->assertFalse(
            $this->service->verifyToken($this->event, $tokenFutur),
            'Un token de fenêtre future ne doit jamais être accepté (anti-rejeu en avant).'
        );
    }

    /** La rotation change réellement le token à chaque fenêtre. */
    public function test_le_token_change_a_chaque_fenetre(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_005)); // fenêtre 66667
        $a = $this->service->currentToken($this->event)['token'];

        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_020)); // fenêtre 66668
        $b = $this->service->currentToken($this->event)['token'];

        $this->assertNotSame($a, $b, 'Deux fenêtres adjacentes doivent produire des tokens distincts.');
    }

    /**
     * Le token est LIÉ à l'événement : à secret identique mais id différent, le
     * token de l'événement A est refusé sur l'événement B (l'id entre dans le HMAC).
     */
    public function test_le_token_est_lie_a_levenement_meme_secret(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_007));
        $tokenA = $this->service->currentToken($this->event)['token'];

        $autre = new Event;
        $autre->id = 99;
        $autre->qr_secret = 'secret-de-test-hmac'; // MÊME secret, id différent

        $this->assertFalse(
            $this->service->verifyToken($autre, $tokenA),
            'Un token ne doit jamais être valide sur un autre événement, même à secret identique.'
        );
    }

    /** Régénérer le secret de l'événement révoque immédiatement les tokens en cours. */
    public function test_changer_le_secret_invalide_les_tokens_existants(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_007));
        $token = $this->service->currentToken($this->event)['token'];
        $this->assertTrue($this->service->verifyToken($this->event, $token));

        $this->event->qr_secret = 'nouveau-secret-apres-rotation';
        $this->assertFalse(
            $this->service->verifyToken($this->event, $token),
            'Un token émis avec l\'ancien secret doit être rejeté après régénération.'
        );
    }

    /** Le temps restant est toujours dans [1, 15] — jamais 0 (une fenêtre vive dure au moins 1 s). */
    public function test_expires_in_reste_dans_les_bornes(): void
    {
        foreach ([1_000_005, 1_000_006, 1_000_014, 1_000_019, 1_000_020] as $ts) {
            Carbon::setTestNow(Carbon::createFromTimestamp($ts));
            $expiresIn = $this->service->currentToken($this->event)['expires_in'];
            $this->assertGreaterThanOrEqual(1, $expiresIn);
            $this->assertLessThanOrEqual(QrTokenService::WINDOW_SECONDS, $expiresIn);
        }
    }

    // =====================================================================
    // Robustesse défensive du ticket de scan (parsing hostile)
    // =====================================================================

    /** Un ticket dont le payload a été altéré casse la signature → refus. */
    public function test_ticket_de_scan_altere_est_rejete(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(2_000_000));
        $ticket = $this->service->issueScanTicket($this->event);

        [$payload, $signature] = explode('.', $ticket);
        // On décode le payload, on modifie l'horodatage puis on ré-encode en gardant
        // la signature d'origine : celle-ci ne couvre plus le nouveau contenu → refus.
        // (Modifier le dernier caractère base64url ne suffit pas : la malléabilité des
        // bits de fin peut laisser les octets décodés inchangés.)
        $decode = base64_decode(strtr($payload, '-_', '+/'), true);
        $this->assertNotFalse($decode);
        $falsifie = str_replace('2000000', '2000001', $decode);
        $this->assertNotSame($decode, $falsifie);
        $payloadFalsifie = rtrim(strtr(base64_encode($falsifie), '+/', '-_'), '=');

        $this->assertFalse($this->service->verifyScanTicket($this->event, $payloadFalsifie.'.'.$signature));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function ticketsMalformes(): array
    {
        return [
            'vide' => [''],
            'un seul segment' => ['abcdef'],
            'trois segments' => ['a.b.c'],
            'base64 invalide' => ['!!!.###'],
            'signature vide' => ['abcdef.'],
        ];
    }

    #[DataProvider('ticketsMalformes')]
    public function test_ticket_de_scan_malforme_est_rejete(string $ticket): void
    {
        $this->assertFalse($this->service->verifyScanTicket($this->event, $ticket));
    }

    /**
     * Ticket à signature VALIDE mais payload sans séparateur « | » : la branche
     * défensive de parsing (issuedAt manquant) doit refuser plutôt que planter.
     * On forge le ticket avec le vrai secret pour franchir la vérification HMAC.
     */
    public function test_ticket_signe_mais_payload_malforme_est_rejete(): void
    {
        $secret = 'secret-de-test-hmac';
        $payloadSansPipe = '42'; // ni « id|timestamp » : pas de séparateur
        $sig = hash_hmac('sha256', $payloadSansPipe, $secret, true);
        $ticket = $this->b64url($payloadSansPipe).'.'.$this->b64url($sig);

        $this->assertFalse(
            $this->service->verifyScanTicket($this->event, $ticket),
            'Un payload sans horodatage doit être refusé même signé correctement.'
        );
    }

    /**
     * Ticket à signature VALIDE mais forgé pour un AUTRE id d'événement : la
     * vérification « bon événement » doit rejeter (defense in depth au-delà du HMAC).
     */
    public function test_ticket_signe_pour_un_autre_id_est_rejete(): void
    {
        $secret = 'secret-de-test-hmac';
        Carbon::setTestNow(Carbon::createFromTimestamp(3_000_000));
        $payload = '99|'.Carbon::now()->getTimestamp(); // id 99, mais l'événement est 42
        $sig = hash_hmac('sha256', $payload, $secret, true);
        $ticket = $this->b64url($payload).'.'.$this->b64url($sig);

        $this->assertFalse($this->service->verifyScanTicket($this->event, $ticket));
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
