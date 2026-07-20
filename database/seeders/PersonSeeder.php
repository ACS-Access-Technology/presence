<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PersonSource;
use App\Models\Person;
use Illuminate\Database\Seeder;

/**
 * Quelques personnes de démonstration : personnel ACS importé (invitable) et
 * visiteurs externes reconnus par email.
 */
class PersonSeeder extends Seeder
{
    public function run(): void
    {
        $staff = [
            ['email' => 'awa.kone@acsgroupe.ci', 'last_name' => 'Koné', 'first_name' => 'Awa', 'direction' => 'Direction SI', 'service' => 'Sécurité applicative', 'position' => 'Analyste sécurité'],
            ['email' => 'k.ndri@acsgroupe.ci', 'last_name' => "N'Dri", 'first_name' => 'Kouassi', 'direction' => 'Direction SI', 'service' => 'Infrastructure', 'position' => 'Admin systèmes'],
            ['email' => 'm.bamba@acsgroupe.ci', 'last_name' => 'Bamba', 'first_name' => 'Mariam', 'direction' => 'Direction RH', 'service' => 'Formation', 'position' => 'Responsable formation'],
        ];

        foreach ($staff as $row) {
            Person::query()->updateOrCreate(
                ['email' => $row['email']],
                [...$row, 'company' => 'ACS Groupe', 'is_staff' => true, 'source' => PersonSource::Import],
            );
        }

        $visitors = [
            ['email' => 'f.diallo@nsia.ci', 'last_name' => 'Diallo', 'first_name' => 'Fatou', 'company' => 'NSIA Banque', 'direction' => 'Direction Risques', 'service' => 'Conformité', 'position' => 'Responsable conformité', 'phone' => '+225 05 44 90 12 03'],
            ['email' => 'm.traore@orange.ci', 'last_name' => 'Traoré', 'first_name' => 'Mamadou', 'company' => 'Orange CI', 'direction' => 'Direction Technique', 'service' => 'Réseaux', 'position' => 'Ingénieur réseau', 'phone' => '+225 07 55 12 88 21'],
        ];

        foreach ($visitors as $row) {
            Person::query()->updateOrCreate(
                ['email' => $row['email']],
                [...$row, 'is_staff' => false, 'source' => PersonSource::Emargement],
            );
        }
    }
}
