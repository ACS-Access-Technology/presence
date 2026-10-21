# Épic 1 — Annuaire des agents par filiale · Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter à Presence un annuaire des agents (collaborateurs internes) rangé par filiale, avec un nouveau rôle `Agent`, socle du vivier assignable des futures tâches (Pulse) et correctif du cloisonnement de l'annuaire.

**Architecture:** L'agent est un compte `User` enrichi (pas de modèle séparé) : nouveaux champs d'annuaire sur `users`, nouveau rôle `Agent` (moindre privilège), écran annuaire cloisonné par filiale via `filiale_id`. Aucune nouvelle infrastructure — on suit les conventions Presence existantes.

**Tech Stack:** Laravel ^13.8, PHP ^8.3, MySQL (prod) / SQLite `:memory:` (test), PHPUnit ^12, Blade + CSS public versionné.

## Global Constraints

- `declare(strict_types=1)` en tête de chaque fichier PHP.
- Classes de service/export en `final` ; validation dans des Form Requests (`app/Http/Requests/`).
- Tests avec **PHPUnit ^12** (jamais Pest) ; `use RefreshDatabase` ; base SQLite `:memory:` en test.
- Rôles réels existants : `SuperAdmin` (`super_admin`) | `AdminFiliale` (`admin_filiale`) | `Organisateur` (`organisateur`). On ajoute `Agent` (`agent`).
- Cloisonnement par filiale : `users.filiale_id` (déjà présent). `User` n'est **pas** sous global scope — le scoping annuaire est explicite.
- Assets publics via `versioned_asset()` (jamais `asset()` nu).
- Lancer `./vendor/bin/pint` avant chaque commit. Messages de commit **sans co-auteur Claude**.
- Table du référentiel personnes = `people` (modèle `App\Models\Person`).

---

### Task 1 : Nouveau rôle `Agent`

**Files:**
- Modify: `app/Enums/UserRole.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Feature/AgentRoleTest.php`

**Interfaces:**
- Produces: `UserRole::Agent` (valeur `'agent'`), `UserRole::Agent->label()`, `UserRole::Agent->canManageSettings() === false` ; état de factory `User::factory()->agent()`.

- [ ] **Step 1: Écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_role_agent_existe_et_a_un_libelle(): void
    {
        $this->assertSame('agent', UserRole::Agent->value);
        $this->assertSame('Agent', UserRole::Agent->label());
    }

    public function test_l_agent_ne_gere_pas_les_parametres(): void
    {
        $this->assertFalse(UserRole::Agent->canManageSettings());
    }

    public function test_un_agent_n_accede_pas_aux_parametres(): void
    {
        $agent = User::factory()->agent()->create();
        $this->actingAs($agent)->get(route('admin.settings.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=AgentRoleTest`
Expected: FAIL — `UserRole::Agent` n'existe pas et `factory()->agent()` est indéfini.

- [ ] **Step 3: Ajouter le case au enum**

Dans `app/Enums/UserRole.php`, ajouter le case et ses branches `match` :

```php
    case SuperAdmin = 'super_admin';
    case AdminFiliale = 'admin_filiale';
    case Organisateur = 'organisateur';
    case Agent = 'agent';
```

Dans `label()` :

```php
            self::Organisateur => 'Organisateur',
            self::Agent => 'Agent',
```

`canManageSettings()` reste inchangé (ne renvoie `true` que pour SuperAdmin/AdminFiliale), donc `Agent` y est déjà exclu — aucune modification nécessaire.

- [ ] **Step 4: Ajouter l'état de factory**

Dans `database/factories/UserFactory.php`, après `filialeAdmin()` :

```php
    /** Agent : exécutant de tâches, rattaché à une filiale, sans pouvoir d'admin. */
    public function agent(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => UserRole::Agent]);
    }
```

- [ ] **Step 5: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=AgentRoleTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint app/Enums/UserRole.php database/factories/UserFactory.php tests/Feature/AgentRoleTest.php
git add app/Enums/UserRole.php database/factories/UserFactory.php tests/Feature/AgentRoleTest.php
git commit -m "feat(annuaire): ajoute le rôle Agent (moindre privilège)"
```

---

### Task 2 : Colonnes d'annuaire sur `users`

**Files:**
- Create: `database/migrations/2026_07_27_000001_add_directory_fields_to_users.php`
- Test: `tests/Feature/UserDirectoryFieldsTest.php`

**Interfaces:**
- Produces: colonnes `users.phone`, `users.direction`, `users.service`, `users.position` (string nullable), `users.person_id` (FK nullable → `people`, `nullOnDelete`).

- [ ] **Step 1: Écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserDirectoryFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_colonnes_d_annuaire_existent(): void
    {
        foreach (['phone', 'direction', 'service', 'position', 'person_id'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('users', $column),
                "La colonne users.$column est manquante.",
            );
        }
    }
}
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=UserDirectoryFieldsTest`
Expected: FAIL — colonnes absentes.

- [ ] **Step 3: Écrire la migration**

`database/migrations/2026_07_27_000001_add_directory_fields_to_users.php` :

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Champs d'annuaire du personnel interne (l'agent = compte User enrichi).
 * person_id relie facultativement un agent à sa fiche Person (référentiel des
 * visiteurs) s'il émarge aussi comme participant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone')->nullable()->after('email');
            $table->string('direction')->nullable()->after('phone');
            $table->string('service')->nullable()->after('direction');
            $table->string('position')->nullable()->after('service');
            $table->foreignId('person_id')->nullable()->after('filiale_id')
                ->constrained('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('person_id');
            $table->dropColumn(['phone', 'direction', 'service', 'position']);
        });
    }
};
```

- [ ] **Step 4: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=UserDirectoryFieldsTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint database/migrations/2026_07_27_000001_add_directory_fields_to_users.php tests/Feature/UserDirectoryFieldsTest.php
git add database/migrations/2026_07_27_000001_add_directory_fields_to_users.php tests/Feature/UserDirectoryFieldsTest.php
git commit -m "feat(annuaire): colonnes d'annuaire (phone/direction/service/position/person_id) sur users"
```

---

### Task 3 : Modèle `User` — champs remplissables, relation `person()`, `isAgent()`

**Files:**
- Modify: `app/Models/User.php`
- Test: `tests/Feature/UserDirectoryModelTest.php`

**Interfaces:**
- Consumes: colonnes de Task 2.
- Produces: `User::$fillable` inclut `phone`, `direction`, `service`, `position`, `person_id` ; `User->person()` (BelongsTo Person) ; `User->isAgent(): bool`.

- [ ] **Step 1: Écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDirectoryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_champs_d_annuaire_sont_remplissables(): void
    {
        $user = User::factory()->agent()->create([
            'phone' => '+225 0102030405',
            'direction' => 'Direction IT',
            'service' => 'Développement',
            'position' => 'Analyste',
        ]);

        $this->assertSame('Direction IT', $user->fresh()->direction);
        $this->assertTrue($user->isAgent());
    }

    public function test_un_agent_peut_etre_relie_a_une_personne(): void
    {
        $person = Person::factory()->create();
        $user = User::factory()->agent()->create(['person_id' => $person->id]);

        $this->assertTrue($user->person->is($person));
    }
}
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=UserDirectoryModelTest`
Expected: FAIL — champs non remplissables / relation `person` absente / `isAgent()` indéfini.

> Si `Person::factory()` n'existe pas encore, créer `database/factories/PersonFactory.php` minimal (au moins `email` unique normalisé et `is_staff => true`) en suivant le modèle `Person` — inclure cette création dans ce même commit.

- [ ] **Step 3: Modifier le modèle**

Dans `app/Models/User.php`, étendre l'attribut `#[Fillable]` :

```php
#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'last_login_at', 'filiale_id', 'phone', 'direction', 'service', 'position', 'person_id'])]
```

Ajouter la relation et le helper (après `filiale()`) :

```php
    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function isAgent(): bool
    {
        return $this->role === UserRole::Agent;
    }
```

- [ ] **Step 4: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=UserDirectoryModelTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Models/User.php tests/Feature/UserDirectoryModelTest.php
git add app/Models/User.php tests/Feature/UserDirectoryModelTest.php database/factories/PersonFactory.php
git commit -m "feat(annuaire): champs d'annuaire + relation person() + isAgent() sur User"
```

---

### Task 4 : Écran annuaire cloisonné par filiale (lecture)

**Files:**
- Create: `app/Http/Controllers/Admin/AgentDirectoryController.php`
- Create: `resources/views/admin/annuaire/index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AgentDirectoryTest.php`

**Interfaces:**
- Consumes: rôle `Agent` (Task 1), champs d'annuaire (Tasks 2-3).
- Produces: route nommée `admin.annuaire.index` (GET `/admin/annuaire`) ; `AgentDirectoryController::index()` retourne la vue `admin.annuaire.index` avec `$agents` (Collection de `User` de la filiale de l'acteur ; toutes les filiales pour un SuperAdmin).

- [ ] **Step 1: Écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Filiale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_admin_filiale_ne_voit_que_les_agents_de_sa_filiale(): void
    {
        $filialeA = Filiale::factory()->create();
        $filialeB = Filiale::factory()->create();

        $admin = User::factory()->filialeAdmin()->forFiliale($filialeA)->create();
        $agentA = User::factory()->agent()->forFiliale($filialeA)->create(['name' => 'Agent Alpha']);
        $agentB = User::factory()->agent()->forFiliale($filialeB)->create(['name' => 'Agent Beta']);

        $response = $this->actingAs($admin)->get(route('admin.annuaire.index'));

        $response->assertOk()->assertSee('Agent Alpha')->assertDontSee('Agent Beta');
    }

    public function test_un_super_admin_voit_toutes_les_filiales(): void
    {
        $filialeA = Filiale::factory()->create();
        $filialeB = Filiale::factory()->create();
        User::factory()->agent()->forFiliale($filialeA)->create(['name' => 'Agent Alpha']);
        User::factory()->agent()->forFiliale($filialeB)->create(['name' => 'Agent Beta']);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.annuaire.index'));

        $response->assertOk()->assertSee('Agent Alpha')->assertSee('Agent Beta');
    }
}
```

> Vérifier au préalable que `Filiale::factory()` existe (utilisé par les tests multi-filiales existants comme `MultiFilialeAdminTest`). Si absent, le créer minimalement dans ce commit.

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=AgentDirectoryTest`
Expected: FAIL — route `admin.annuaire.index` inconnue.

- [ ] **Step 3: Écrire le contrôleur**

`app/Http/Controllers/Admin/AgentDirectoryController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Annuaire du personnel interne, cloisonné par filiale.
 *
 * L'agent est un compte User enrichi (phone/direction/service/position).
 * Cloisonnement explicite : User n'est pas sous global scope de filiale
 * (voir ApplyFilialeScope, qui ne couvre que les entités métier).
 */
final class AgentDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();

        $query = User::query()
            ->with('filiale')
            ->orderBy('name');

        // Le SuperAdmin (filiale_id NULL, au-dessus des filiales) voit tout ;
        // tout autre compte est borné à sa propre filiale.
        if (! $actor->isSuperAdmin()) {
            $query->where('filiale_id', $actor->filiale_id);
        }

        return view('admin.annuaire.index', [
            'agents' => $query->get(),
        ]);
    }
}
```

- [ ] **Step 4: Écrire la vue**

`resources/views/admin/annuaire/index.blade.php` :

```blade
@extends('layouts.admin')

@section('title', 'Annuaire des agents')

@section('content')
    <div class="page-header">
        <h1>Annuaire des agents</h1>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Nom</th>
                <th>Poste</th>
                <th>Direction</th>
                <th>Service</th>
                <th>Téléphone</th>
                <th>Email</th>
                @if(auth()->user()->isSuperAdmin())
                    <th>Filiale</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($agents as $agent)
                <tr>
                    <td>{{ $agent->name }}</td>
                    <td>{{ $agent->position }}</td>
                    <td>{{ $agent->direction }}</td>
                    <td>{{ $agent->service }}</td>
                    <td>{{ $agent->phone }}</td>
                    <td>{{ $agent->email }}</td>
                    @if(auth()->user()->isSuperAdmin())
                        <td>{{ $agent->filiale?->name ?? '—' }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="7">Aucun agent dans cette filiale.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
```

> Adapter les classes CSS (`page-header`, `data-table`) à celles réellement utilisées par `layouts/admin.blade.php` ; consulter une vue admin existante (ex. `resources/views/admin/settings/index.blade.php`) pour l'ossature exacte des sections et classes.

- [ ] **Step 5: Déclarer la route**

Dans `routes/web.php`, à l'intérieur du groupe admin authentifié (`['auth', 'session.active', 'filiale.scope']`, celui qui contient déjà les routes `admin.*`), ajouter :

```php
    Route::get('/annuaire', [\App\Http\Controllers\Admin\AgentDirectoryController::class, 'index'])
        ->name('annuaire.index');
```

- [ ] **Step 6: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=AgentDirectoryTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Pint + commit**

```bash
./vendor/bin/pint app/Http/Controllers/Admin/AgentDirectoryController.php tests/Feature/AgentDirectoryTest.php
git add app/Http/Controllers/Admin/AgentDirectoryController.php resources/views/admin/annuaire/index.blade.php routes/web.php tests/Feature/AgentDirectoryTest.php
git commit -m "feat(annuaire): écran annuaire des agents cloisonné par filiale"
```

---

### Task 5 : Lien de navigation vers l'annuaire

**Files:**
- Modify: `resources/views/layouts/admin.blade.php`
- Test: `tests/Feature/AgentDirectoryTest.php` (ajout d'un test de présence du lien)

**Interfaces:**
- Consumes: route `admin.annuaire.index` (Task 4).

- [ ] **Step 1: Ajouter le test qui échoue**

Ajouter dans `tests/Feature/AgentDirectoryTest.php` :

```php
    public function test_le_lien_annuaire_est_visible_dans_la_navigation(): void
    {
        $admin = User::factory()->filialeAdmin()->create();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.annuaire.index'));
    }
```

> Si le nom de la route du tableau de bord diffère de `admin.dashboard`, l'ajuster (le vérifier dans `routes/web.php`).

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=AgentDirectoryTest`
Expected: FAIL — le lien n'est pas rendu.

- [ ] **Step 3: Ajouter le lien dans le layout**

Dans `resources/views/layouts/admin.blade.php`, à l'endroit du menu de navigation (repérer les liens existants, ex. vers le tableau de bord / les paramètres), ajouter :

```blade
<a href="{{ route('admin.annuaire.index') }}"
   class="{{ request()->routeIs('admin.annuaire.*') ? 'active' : '' }}">
    Annuaire
</a>
```

> Reprendre exactement la même structure de balise et les mêmes classes que les liens de navigation voisins déjà présents dans ce layout.

- [ ] **Step 4: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=AgentDirectoryTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint resources/views/layouts/admin.blade.php
git add resources/views/layouts/admin.blade.php tests/Feature/AgentDirectoryTest.php
git commit -m "feat(annuaire): lien de navigation vers l'annuaire"
```

---

### Task 6 : Édition des champs d'annuaire d'un agent (écriture, manager)

**Files:**
- Create: `app/Http/Requests/Admin/UpdateAgentDirectoryRequest.php`
- Modify: `app/Http/Controllers/Admin/AgentDirectoryController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AgentDirectoryEditTest.php`

**Interfaces:**
- Consumes: `AgentDirectoryController` (Task 4), champs (Tasks 2-3).
- Produces: route nommée `admin.annuaire.update` (PATCH `/admin/annuaire/{agent}`), réservée aux managers (`role:admin_filiale,super_admin`) ; `AgentDirectoryController::update(UpdateAgentDirectoryRequest, User $agent)`.

- [ ] **Step 1: Écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Filiale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentDirectoryEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_admin_filiale_edite_les_champs_d_annuaire_d_un_agent(): void
    {
        $filiale = Filiale::factory()->create();
        $admin = User::factory()->filialeAdmin()->forFiliale($filiale)->create();
        $agent = User::factory()->agent()->forFiliale($filiale)->create();

        $this->actingAs($admin)->patch(route('admin.annuaire.update', $agent), [
            'phone' => '+225 0708091011',
            'direction' => 'Direction IT',
            'service' => 'Sécurité',
            'position' => 'Consultant',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $agent->id,
            'direction' => 'Direction IT',
            'position' => 'Consultant',
        ]);
    }

    public function test_un_agent_ne_peut_pas_editer_l_annuaire(): void
    {
        $agent = User::factory()->agent()->create();
        $autre = User::factory()->agent()->create();

        $this->actingAs($agent)->patch(route('admin.annuaire.update', $autre), [
            'phone' => '+225 0000000000',
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=AgentDirectoryEditTest`
Expected: FAIL — route `admin.annuaire.update` inconnue.

- [ ] **Step 3: Écrire le Form Request**

`app/Http/Requests/Admin/UpdateAgentDirectoryRequest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAgentDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // l'accès est déjà borné par le middleware role: (voir route).
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', 'string', 'max:120'],
            'service' => ['nullable', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:120'],
        ];
    }
}
```

- [ ] **Step 4: Ajouter la méthode `update()` au contrôleur**

Dans `app/Http/Controllers/Admin/AgentDirectoryController.php`, ajouter l'import `use App\Http\Requests\Admin\UpdateAgentDirectoryRequest;` puis :

```php
    public function update(UpdateAgentDirectoryRequest $request, User $agent): \Illuminate\Http\RedirectResponse
    {
        // Un AdminFiliale ne peut éditer qu'un agent de sa propre filiale.
        $actor = $request->user();
        if (! $actor->isSuperAdmin() && $agent->filiale_id !== $actor->filiale_id) {
            abort(403);
        }

        $agent->update($request->validated());

        return redirect()->route('admin.annuaire.index')
            ->with('status', 'Fiche annuaire mise à jour.');
    }
```

- [ ] **Step 5: Déclarer la route (managers uniquement)**

Dans `routes/web.php`, dans le sous-groupe `role:admin_filiale,super_admin` (celui des Paramètres) OU dans un groupe équivalent, ajouter :

```php
    Route::patch('/annuaire/{agent}', [\App\Http\Controllers\Admin\AgentDirectoryController::class, 'update'])
        ->name('annuaire.update');
```

> `{agent}` est lié par route-model-binding à `User`. Le contrôle filiale est fait dans `update()` ; le contrôle de rôle par le middleware du groupe.

- [ ] **Step 6: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=AgentDirectoryEditTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Suite complète + Pint + commit**

```bash
php artisan test
./vendor/bin/pint app/Http/Requests/Admin/UpdateAgentDirectoryRequest.php app/Http/Controllers/Admin/AgentDirectoryController.php tests/Feature/AgentDirectoryEditTest.php
git add app/Http/Requests/Admin/UpdateAgentDirectoryRequest.php app/Http/Controllers/Admin/AgentDirectoryController.php routes/web.php tests/Feature/AgentDirectoryEditTest.php
git commit -m "feat(annuaire): édition des fiches d'annuaire par les managers, cloisonnée par filiale"
```

---

## Auto-contrôle du plan (writing-plans)

- **Couverture spec** (conception §3) : rôle `Agent` ✔ (T1) ; champs d'annuaire ✔ (T2-3) ; lien Person ✔ (T3) ; annuaire par filiale + SuperAdmin voit tout ✔ (T4) ; navigation ✔ (T5) ; édition manager cloisonnée ✔ (T6). Correctif RGPD-03 : l'annuaire agents est cloisonné par filiale dès T4 ; le durcissement du `PersonSearchController` (annuaire visiteurs) reste hors périmètre de cet épic et sera traité avec le module d'invitation/assignation.
- **Placeholders** : aucun « TODO/TBD » ; code réel fourni à chaque étape. Les rares notes « > vérifier … » portent sur l'alignement aux conventions existantes (classes CSS, noms de routes), pas sur du code manquant.
- **Cohérence des types** : `UserRole::Agent`, `User::factory()->agent()`, `admin.annuaire.index`, `admin.annuaire.update`, `AgentDirectoryController::index/update` employés de façon cohérente entre tâches.

## Épics suivants (à planifier séparément)

2. Projets / phases / tâches + machine à états + `task_events`.
3. Soumission / résolution (livrable + validation par l'émetteur) + demandes complémentaires.
4. Business review décadaire (pack + snapshot + décisions → tâches).
5. Notifications & rappels (transactionnelles + cron).
6. Écran PDG « ce qui est / ce qui n'est pas » + vues transversales + export Excel.
