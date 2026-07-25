<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Filiale;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD des comptes internes (Paramètres). Ouvert à SuperAdmin et AdminFiliale,
 * mais fortement cloisonné (Lot D, T-ME-13) après la revue de sécurité :
 *
 *  - **Anti-escalade de privilège** : le rôle `super_admin` ne peut JAMAIS être
 *    attribué via cette surface (ni à la création, ni à la mise à jour). Un
 *    AdminFiliale ne peut créer/gérer que des `Organisateur` de SA filiale.
 *  - **Anti-IDOR** : chaque action sur un compte cible passe par la {@see UserPolicy}
 *    (`User` n'a pas de global scope — voir la note de la policy) → 403 sur un
 *    compte d'une autre filiale.
 *  - **Changement de filiale** : impossible via `update` ; réservé à l'action
 *    dédiée `reassign` (SuperAdmin uniquement).
 *
 * Pas d'inscription publique ni de « mot de passe oublié » : un admin crée les
 * comptes et transmet un mot de passe temporaire.
 */
class AccountController extends Controller
{
    /** Rôles assignables via cette surface (jamais `super_admin` : anti-escalade). */
    private const array ASSIGNABLE_ROLES = [UserRole::AdminFiliale->value, UserRole::Organisateur->value];

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:191', 'unique:users,email'],
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
            // Filiale cible : imposée à celle de l'AdminFiliale (input ignoré) ;
            // pour le SuperAdmin, la filiale choisie, à défaut la filiale du
            // contexte courant (sélecteur de topbar), à défaut la holding.
            'filiale_id' => ['nullable', 'integer', Rule::exists('filiales', 'id')->where('is_active', true)],
        ]);

        $role = UserRole::from($data['role']);
        // La règle de validation `integer` vérifie le format mais ne caste pas la
        // valeur : `$data['filiale_id']` reste une chaîne côté FormData. Cast
        // explicite requis (strict_types=1).
        $filialeId = $this->resolveTargetFiliale($actor, isset($data['filiale_id']) ? (int) $data['filiale_id'] : null);

        $this->assertActorCanAssign($actor, $role);

        $password = Str::password(12);
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($password),
            'role' => $role,
            'filiale_id' => $filialeId,
            'is_active' => true,
        ]);

        return response()->json(['account' => $this->payload($user), 'temp_password' => $password], 201);
    }

    public function update(Request $request, User $account): JsonResponse
    {
        Gate::authorize('manage', $account);

        $actor = $request->user();

        $data = $request->validate([
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
            'is_active' => ['required', 'boolean'],
        ]);

        $role = UserRole::from($data['role']);
        $this->assertActorCanAssign($actor, $role);

        // Garde-fou anti-verrouillage : on ne peut ni se désactiver ni se rétrograder soi-même.
        if ($account->is($actor) && ($data['is_active'] === false || $role !== $actor->role)) {
            return response()->json(['message' => 'Vous ne pouvez pas retirer votre propre accès.'], 422);
        }

        // `filiale_id` n'est jamais modifié ici (réservé à reassign, SuperAdmin).
        $account->update(['role' => $role, 'is_active' => $data['is_active']]);

        return response()->json($this->payload($account));
    }

    /**
     * Réassigne un compte à une autre filiale (SuperAdmin uniquement, T-ME-13).
     * Les événements déjà créés par ce compte ne suivent PAS (dénormalisation
     * volontaire : l'événement porte sa propre filiale) — l'UI en avertit.
     */
    public function reassign(Request $request, User $account): JsonResponse
    {
        Gate::authorize('reassign', User::class);

        if ($account->isSuperAdmin()) {
            return response()->json(['message' => 'Un super administrateur n\'appartient à aucune filiale.'], 422);
        }

        $data = $request->validate([
            'filiale_id' => ['required', 'integer', Rule::exists('filiales', 'id')->where('is_active', true)],
        ]);

        $account->update(['filiale_id' => (int) $data['filiale_id']]);

        return response()->json($this->payload($account->refresh()));
    }

    public function resetPassword(Request $request, User $account): JsonResponse
    {
        Gate::authorize('manage', $account);

        $password = Str::password(12);
        $account->update(['password' => Hash::make($password)]);

        return response()->json(['temp_password' => $password]);
    }

    public function destroy(Request $request, User $account): JsonResponse
    {
        Gate::authorize('manage', $account);

        if ($account->is($request->user())) {
            return response()->json(['message' => 'Vous ne pouvez pas supprimer votre propre compte.'], 422);
        }

        $account->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Détermine la filiale cible d'un compte à créer : forcée à celle de l'acteur
     * pour un AdminFiliale (il ne crée jamais hors de sa filiale), choisie pour un
     * SuperAdmin.
     */
    private function resolveTargetFiliale(User $actor, ?int $requested): int
    {
        if ($actor->isSuperAdmin()) {
            return $requested ?? Filiale::resolveForCreation();
        }

        // AdminFiliale : filiale imposée (fail-closed si incohérence de données).
        if ($actor->filiale_id === null) {
            throw ValidationException::withMessages(['filiale_id' => 'Compte sans filiale : opération impossible.']);
        }

        return $actor->filiale_id;
    }

    /**
     * Un AdminFiliale ne peut assigner que le rôle `Organisateur`. Le SuperAdmin
     * peut assigner `AdminFiliale` ou `Organisateur`. `super_admin` est déjà exclu
     * par la validation (anti-escalade). Barrière redondante et explicite.
     */
    private function assertActorCanAssign(User $actor, UserRole $role): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if (! $actor->isFilialeAdmin() || $role !== UserRole::Organisateur) {
            throw ValidationException::withMessages([
                'role' => 'Vous ne pouvez créer que des comptes Organisateur dans votre filiale.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'is_active' => $user->is_active,
            'filiale_id' => $user->filiale_id,
            'filiale_name' => $user->filiale_id !== null ? $user->filiale?->name : null,
        ];
    }
}
