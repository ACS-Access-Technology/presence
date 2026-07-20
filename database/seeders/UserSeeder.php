<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
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
        User::query()->updateOrCreate(
            ['email' => 'admin@acsgroupe.ci'],
            [
                'name' => "N'Guessan Koffi",
                'password' => Hash::make('password'),
                'role' => UserRole::Admin,
                'is_active' => true,
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'organisateur@acsgroupe.ci'],
            [
                'name' => 'Awa Diomandé',
                'password' => Hash::make('password'),
                'role' => UserRole::Organisateur,
                'is_active' => true,
            ],
        );
    }
}
