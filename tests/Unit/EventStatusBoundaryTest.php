<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Statut dérivé {@see Event::status()} — vérification À LA SECONDE PRÈS des
 * transitions aux bornes exactes de `starts_at` / `ends_at`, et de l'ordre de
 * priorité annulé › clos › en cours › à venir.
 *
 * Rappel de la logique testée (Event.php) :
 *   - `cancelled_at !== null`                        → Annulé  (prime sur tout)
 *   - `closed_at !== null` OU `now > ends_at`        → Clos
 *   - `now >= starts_at`                             → En cours
 *   - sinon                                          → À venir
 *
 * Subtilité clé : la borne de fin est STRICTE (`greaterThan`) alors que la borne
 * de début est INCLUSIVE (`greaterThanOrEqualTo`). Donc à la seconde EXACTE de
 * `ends_at`, l'événement est encore « En cours » (pas « Clos »).
 *
 * Test unitaire pur (sans base ni app bootée) : `new Event` non persisté, on
 * renseigne uniquement les colonnes lues par status().
 */
class EventStatusBoundaryTest extends TestCase
{
    private function event(Carbon $start, Carbon $end): Event
    {
        $event = new Event;
        $event->starts_at = $start;
        $event->ends_at = $end;

        return $event;
    }

    // ======================================================================
    // Échelle temporelle à venir → en cours → clos, seconde par seconde
    // ======================================================================

    public function test_a_venir_une_seconde_avant_le_debut(): void
    {
        $start = Carbon::parse('2026-03-10 09:00:00');
        $end = Carbon::parse('2026-03-10 11:00:00');
        $event = $this->event($start, $end);

        $this->assertSame(EventStatus::AVenir, $event->status($start->copy()->subSecond()));
    }

    public function test_en_cours_a_la_seconde_exacte_du_debut(): void
    {
        $start = Carbon::parse('2026-03-10 09:00:00');
        $end = Carbon::parse('2026-03-10 11:00:00');
        $event = $this->event($start, $end);

        // Borne de début INCLUSIVE : à starts_at pile, l'événement a commencé.
        $this->assertSame(EventStatus::EnCours, $event->status($start->copy()));
    }

    public function test_en_cours_une_seconde_apres_le_debut(): void
    {
        $start = Carbon::parse('2026-03-10 09:00:00');
        $end = Carbon::parse('2026-03-10 11:00:00');
        $event = $this->event($start, $end);

        $this->assertSame(EventStatus::EnCours, $event->status($start->copy()->addSecond()));
    }

    public function test_en_cours_une_seconde_avant_la_fin(): void
    {
        $start = Carbon::parse('2026-03-10 09:00:00');
        $end = Carbon::parse('2026-03-10 11:00:00');
        $event = $this->event($start, $end);

        $this->assertSame(EventStatus::EnCours, $event->status($end->copy()->subSecond()));
    }

    public function test_en_cours_a_la_seconde_exacte_de_la_fin(): void
    {
        $start = Carbon::parse('2026-03-10 09:00:00');
        $end = Carbon::parse('2026-03-10 11:00:00');
        $event = $this->event($start, $end);

        // Borne de fin STRICTE : à ends_at pile, `now > ends_at` est FAUX → encore
        // En cours. C'est la seconde charnière la plus piégeuse du cycle de vie.
        $this->assertSame(EventStatus::EnCours, $event->status($end->copy()));
    }

    public function test_clos_une_seconde_apres_la_fin(): void
    {
        $start = Carbon::parse('2026-03-10 09:00:00');
        $end = Carbon::parse('2026-03-10 11:00:00');
        $event = $this->event($start, $end);

        $this->assertSame(EventStatus::Clos, $event->status($end->copy()->addSecond()));
    }

    // ======================================================================
    // Clôture manuelle (closed_at) : prime sur l'horloge
    // ======================================================================

    public function test_clos_si_closed_at_pose_meme_avant_la_fin(): void
    {
        $start = Carbon::parse('2026-03-10 09:00:00');
        $end = Carbon::parse('2026-03-10 11:00:00');
        $event = $this->event($start, $end);
        $event->closed_at = Carbon::parse('2026-03-10 10:00:00'); // clôture anticipée

        // En pleine fenêtre horaire, mais closed_at posé → Clos malgré now < ends_at.
        $this->assertSame(EventStatus::Clos, $event->status(Carbon::parse('2026-03-10 10:30:00')));
    }

    public function test_clos_si_closed_at_pose_meme_avant_le_debut(): void
    {
        $start = Carbon::parse('2026-03-10 09:00:00');
        $end = Carbon::parse('2026-03-10 11:00:00');
        $event = $this->event($start, $end);
        $event->closed_at = Carbon::parse('2026-03-09 12:00:00');

        // closed_at prime même sur « à venir ».
        $this->assertSame(EventStatus::Clos, $event->status(Carbon::parse('2026-03-09 13:00:00')));
    }

    // ======================================================================
    // Priorité de l'annulation : annulé › clos › en cours › à venir
    // ======================================================================

    public function test_annule_prime_pendant_la_fenetre_en_cours(): void
    {
        $start = Carbon::parse('2026-03-10 09:00:00');
        $end = Carbon::parse('2026-03-10 11:00:00');
        $event = $this->event($start, $end);
        $event->cancelled_at = Carbon::parse('2026-03-10 08:00:00');

        $this->assertSame(EventStatus::Annule, $event->status(Carbon::parse('2026-03-10 10:00:00')));
    }

    public function test_annule_prime_sur_clos_quand_la_fin_est_depassee(): void
    {
        $start = Carbon::parse('2026-03-10 09:00:00');
        $end = Carbon::parse('2026-03-10 11:00:00');
        $event = $this->event($start, $end);
        $event->cancelled_at = Carbon::parse('2026-03-10 08:00:00');

        // now > ends_at ferait « Clos », mais l'annulation prime.
        $this->assertSame(EventStatus::Annule, $event->status(Carbon::parse('2026-03-10 12:00:00')));
    }

    public function test_annule_prime_meme_si_closed_at_est_aussi_pose(): void
    {
        $start = Carbon::parse('2026-03-10 09:00:00');
        $end = Carbon::parse('2026-03-10 11:00:00');
        $event = $this->event($start, $end);
        $event->closed_at = Carbon::parse('2026-03-10 11:00:00');
        $event->cancelled_at = Carbon::parse('2026-03-10 08:00:00');

        $this->assertSame(EventStatus::Annule, $event->status(Carbon::parse('2026-03-10 12:00:00')));
    }

    public function test_annule_prime_avant_meme_le_debut(): void
    {
        $start = Carbon::parse('2026-03-10 09:00:00');
        $end = Carbon::parse('2026-03-10 11:00:00');
        $event = $this->event($start, $end);
        $event->cancelled_at = Carbon::parse('2026-03-05 09:00:00');

        $this->assertSame(EventStatus::Annule, $event->status(Carbon::parse('2026-03-08 09:00:00')));
    }

    // ======================================================================
    // Événement de durée nulle (starts_at == ends_at) — cas dégénéré
    // ======================================================================

    public function test_evenement_de_duree_nulle_est_en_cours_a_l_instant_pile(): void
    {
        $instant = Carbon::parse('2026-03-10 09:00:00');
        $event = $this->event($instant->copy(), $instant->copy());

        // À l'instant pile : now >= starts_at (vrai) ET now > ends_at (faux) → En cours.
        $this->assertSame(EventStatus::EnCours, $event->status($instant->copy()));
        // Une seconde après : clos.
        $this->assertSame(EventStatus::Clos, $event->status($instant->copy()->addSecond()));
        // Une seconde avant : à venir.
        $this->assertSame(EventStatus::AVenir, $event->status($instant->copy()->subSecond()));
    }
}
