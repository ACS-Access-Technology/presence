<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrée du journal d'audit (voir migration create_audit_logs_table).
 *
 * Append-only : {@see self::UPDATED_AT} désactivé → seul `created_at` est géré.
 * Aucun global scope de filiale : l'audit est transversal à la holding et n'est
 * consulté que par le SuperAdmin.
 *
 * @property int $id
 * @property ?int $user_id
 * @property ?string $actor_name
 * @property ?string $actor_email
 * @property string $action
 * @property ?string $subject_type
 * @property ?int $subject_id
 * @property ?array<string, mixed> $before
 * @property ?array<string, mixed> $after
 */
final class AuditLog extends Model
{
    /** Journal immuable : pas d'`updated_at`. */
    public const ?string UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'actor_name', 'actor_email',
        'action', 'subject_type', 'subject_id',
        'before', 'after',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Enregistre une opération. L'acteur est dénormalisé (nom/email) pour que la
     * trace survive à la suppression du compte.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        string $action,
        Model $subject,
        ?array $before,
        ?array $after,
        ?User $actor,
    ): self {
        return self::create([
            'user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after,
        ]);
    }
}
