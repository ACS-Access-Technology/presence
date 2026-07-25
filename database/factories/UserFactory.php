<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Filiale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Organisateur,
            // Par défaut, rattaché à la filiale par défaut « ACS Groupe » (créée
            // par la migration). Surchargé par forFiliale() / superAdmin().
            'filiale_id' => Filiale::defaultId(),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Compte SuperAdmin (au-dessus des filiales, filiale_id NULL). Conserve le
     * nom `admin()` pour compatibilité avec les tests existants (accès Paramètres).
     */
    public function admin(): static
    {
        return $this->superAdmin();
    }

    /** SuperAdmin : transversal, sans filiale (D-ME-6). */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => UserRole::SuperAdmin,
            'filiale_id' => null,
        ]);
    }

    /** AdminFiliale : rattaché à une filiale (par défaut, la filiale par défaut). */
    public function filialeAdmin(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => UserRole::AdminFiliale]);
    }

    /** Rattache le compte à une filiale précise. */
    public function forFiliale(Filiale $filiale): static
    {
        return $this->state(fn (array $attributes): array => ['filiale_id' => $filiale->id]);
    }

    /** Compte désactivé (ne peut pas se connecter). */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
