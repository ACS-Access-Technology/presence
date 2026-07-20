<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * CRUD des comptes organisateurs internes (Paramètres, admin only).
 *
 * Pas d'inscription publique : les comptes sont créés ici. Pas de « mot de passe
 * oublié » en self-service — un admin réinitialise et transmet le mot de passe temporaire.
 */
class AccountController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:191', 'unique:users,email'],
            'role' => ['required', new Enum(UserRole::class)],
        ]);

        $password = Str::password(12);
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($password),
            'role' => $data['role'],
            'is_active' => true,
        ]);

        return response()->json(['account' => $this->payload($user), 'temp_password' => $password], 201);
    }

    public function update(Request $request, User $account): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', new Enum(UserRole::class)],
            'is_active' => ['required', 'boolean'],
        ]);

        // Garde-fou anti-verrouillage : on ne peut ni se désactiver ni se rétrograder soi-même.
        if ($account->is($request->user()) && ($data['is_active'] === false || $data['role'] !== UserRole::Admin->value)) {
            return response()->json(['message' => 'Vous ne pouvez pas retirer votre propre accès administrateur.'], 422);
        }

        $account->update($data);

        return response()->json($this->payload($account));
    }

    public function resetPassword(Request $request, User $account): JsonResponse
    {
        $password = Str::password(12);
        $account->update(['password' => Hash::make($password)]);

        return response()->json(['temp_password' => $password]);
    }

    public function destroy(Request $request, User $account): JsonResponse
    {
        if ($account->is($request->user())) {
            return response()->json(['message' => 'Vous ne pouvez pas supprimer votre propre compte.'], 422);
        }

        $account->delete();

        return response()->json(['deleted' => true]);
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
        ];
    }
}
