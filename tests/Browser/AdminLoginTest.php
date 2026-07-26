<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Filiale;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Parcours navigateur réel (E2E) : un administrateur se connecte via le vrai
 * formulaire de connexion et atterrit sur son tableau de bord. Contrairement
 * aux tests Feature (qui simulent la requête HTTP), ce test exécute un vrai
 * Chrome contre l'application réellement servie (voir `.env.dusk.local`).
 *
 * `DatabaseTruncation` (pas `DatabaseMigrations`) : Dusk tourne dans un
 * processus HTTP séparé du processus de test, donc `RefreshDatabase` (qui
 * s'appuie sur une transaction non partageable entre processus) est
 * inutilisable ici. `DatabaseTruncation` migre une fois puis TRONQUE entre
 * les tests au lieu de rejouer up()/down() à chaque test (ce dernier cycle
 * fait planter SQLite sur certaines migrations ALTER TABLE historiques).
 */
class AdminLoginTest extends DuskTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        // `DatabaseTruncation` tronque aussi `filiales` sans rejouer l'insertion
        // de la filiale par défaut faite dans la migration — on la recrée ici.
        Filiale::firstOrCreate(['slug' => Filiale::DEFAULT_SLUG], ['name' => 'ACS Groupe', 'is_active' => true]);
        $this->seed(UserSeeder::class);
    }

    public function test_un_administrateur_se_connecte_et_voit_son_tableau_de_bord(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->visit('/connexion')
                ->type('email', 'admin@acsgroupe.ci')
                ->type('password', 'password')
                ->press('Se connecter')
                ->waitForLocation('/admin/events')
                ->assertSee('Événements')
                ->assertSee('N\'Guessan Koffi');
        });
    }

    public function test_un_mauvais_mot_de_passe_reste_sur_la_page_de_connexion(): void
    {
        $this->browse(function (Browser $browser): void {
            // Le navigateur Dusk garde ses cookies d'une méthode de test à
            // l'autre : sans ce logout explicite, une session encore active
            // (test précédent) ferait rediriger /connexion loin du formulaire.
            $browser->logout()
                ->visit('/connexion')
                ->type('email', 'admin@acsgroupe.ci')
                ->type('password', 'mauvais-mot-de-passe')
                ->press('Se connecter')
                ->waitForText('Ces identifiants ne correspondent à aucun compte actif', 5)
                ->assertPathIs('/connexion');
        });
    }
}
