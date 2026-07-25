<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Filiale;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Comptes internes de démonstration (développement).
 * ⚠️ Mots de passe de démo : à changer avant toute mise en production.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        // SuperAdmin : au-dessus des filiales (filiale_id NULL, D-ME-6).
        User::query()->updateOrCreate(
            ['email' => 'admin@acsgroupe.ci'],
            [
                'name' => "N'Guessan Koffi",
                'password' => Hash::make('password'),
                'role' => UserRole::SuperAdmin,
                'filiale_id' => null,
                'is_active' => true,
            ],
        );

        // Organisateur rattaché à la filiale par défaut « ACS Groupe ».
        User::query()->updateOrCreate(
            ['email' => 'organisateur@acsgroupe.ci'],
            [
                'name' => 'Awa Diomandé',
                'password' => Hash::make('password'),
                'role' => UserRole::Organisateur,
                'filiale_id' => Filiale::defaultId(),
                'is_active' => true,
            ],
        );
    }
}
