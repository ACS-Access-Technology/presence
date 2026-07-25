<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Compte interne ACS Groupe (SuperAdmin, AdminFiliale ou Organisateur).
 *
 * `filiale_id` est NULL pour le SuperAdmin (au-dessus des filiales, D-ME-6),
 * renseigné pour AdminFiliale et Organisateur.
 *
 * @property ?int $filiale_id
 * @property UserRole $role
 * @property bool $is_active
 */
#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'last_login_at', 'filiale_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'role' => UserRole::class,
        ];
    }

    /** @return BelongsTo<Filiale, $this> */
    public function filiale(): BelongsTo
    {
        return $this->belongsTo(Filiale::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isFilialeAdmin(): bool
    {
        return $this->role === UserRole::AdminFiliale;
    }

    /** Peut accéder aux Paramètres (SuperAdmin global ou AdminFiliale scopé). */
    public function canManageSettings(): bool
    {
        return $this->role->canManageSettings();
    }
}
