<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Carbon;

/**
 * Génération et validation du QR tournant, SANS ÉTAT (aucune table, aucun cron,
 * aucun worker — compatible hébergement mutualisé).
 *
 * Principe :
 *  - Le token dépend d'une fenêtre temporelle de 15 s : token = HMAC(secret, event|window).
 *    L'écran de projection poll `currentToken()` ~1×/s et régénère le QR.
 *  - Au scan, `verifyToken()` accepte la fenêtre courante ET la précédente
 *    (tolérance de dérive d'horloge / délai scan→ouverture). L'horloge SERVEUR fait foi.
 *  - Comme un formulaire se remplit en 30-60 s, la fraîcheur est vérifiée AU SCAN, qui
 *    émet un « ticket de scan » signé valable 5 min ; c'est ce ticket qui est vérifié
 *    à la soumission (et non le token brut, sinon il serait toujours expiré).
 */
final class QrTokenService
{
    /** Durée de vie d'une fenêtre de token (secondes). */
    public const int WINDOW_SECONDS = 15;

    /** Durée de validité du ticket de scan (secondes) = temps max pour remplir le formulaire. */
    public const int SCAN_TICKET_TTL = 300;

    /**
     * Token courant + secondes restantes avant rotation.
     *
     * @return array{token: string, expires_in: int}
     */
    public function currentToken(Event $event): array
    {
        $ts = Carbon::now()->getTimestamp();
        $window = intdiv($ts, self::WINDOW_SECONDS);

        return [
            'token' => $this->tokenForWindow($event, $window),
            'expires_in' => self::WINDOW_SECONDS - ($ts % self::WINDOW_SECONDS),
        ];
    }

    /** Valide un token scanné (fenêtre courante ou précédente), en temps constant. */
    public function verifyToken(Event $event, string $token): bool
    {
        $window = intdiv(Carbon::now()->getTimestamp(), self::WINDOW_SECONDS);

        foreach ([$window, $window - 1] as $candidate) {
            if (hash_equals($this->tokenForWindow($event, $candidate), $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Émet un ticket de scan signé (autonome, sans état) valable SCAN_TICKET_TTL.
     *
     * Le nonce aléatoire garantit un ticket UNIQUE par émission, même pour deux
     * scans du même événement dans la même seconde : sans lui, le payload
     * (`event_id|seconde`) est déterministe, donc identique pour toute une
     * grappe de visiteurs scannant au même instant — l'anti-rejeu par ticket
     * ({@see PublicAttendanceController::store()}, `attendance-store-ticket`)
     * les confondrait alors avec un seul ticket rejoué et bloquerait à tort
     * le 6e visiteur légitime d'une même seconde (repéré en test de charge).
     */
    public function issueScanTicket(Event $event): string
    {
        $nonce = bin2hex(random_bytes(8));
        $payload = $event->id.'|'.Carbon::now()->getTimestamp().'|'.$nonce;
        $signature = hash_hmac('sha256', $payload, $this->secret($event), true);

        return $this->base64UrlEncode($payload).'.'.$this->base64UrlEncode($signature);
    }

    /** Vérifie un ticket de scan : signature valide, bon événement, non expiré. */
    public function verifyScanTicket(Event $event, string $ticket): bool
    {
        $parts = explode('.', $ticket);
        if (count($parts) !== 2) {
            return false;
        }

        $payload = $this->base64UrlDecode($parts[0]);
        $signature = $this->base64UrlDecode($parts[1]);
        if ($payload === false || $signature === false) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $this->secret($event), true);
        if (! hash_equals($expected, $signature)) {
            return false;
        }

        // Le nonce (3e segment) n'est pas revalidé ici : sa seule fonction est de
        // rendre chaque ticket unique à l'émission (voir issueScanTicket) ; limite
        // à 3 segments pour tolérer, sans casser, un ticket 2 segments encore en
        // circulation juste après un déploiement (fenêtre de 5 min max).
        [$eventId, $issuedAt] = array_pad(explode('|', $payload, 3), 3, null);
        if ((int) $eventId !== $event->id || $issuedAt === null) {
            return false;
        }

        return (Carbon::now()->getTimestamp() - (int) $issuedAt) <= self::SCAN_TICKET_TTL;
    }

    private function tokenForWindow(Event $event, int $window): string
    {
        $raw = hash_hmac('sha256', $event->id.'|'.$window, $this->secret($event), true);

        // 18 octets → 24 caractères base64url : assez d'entropie, QR compact.
        return $this->base64UrlEncode(substr($raw, 0, 18));
    }

    /** Secret HMAC de l'événement, avec repli sur la clé applicative si absent. */
    private function secret(Event $event): string
    {
        return $event->qr_secret ?: (string) config('app.key');
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
