# Test de recrutement en ligne — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre à un candidat externe non authentifié de passer en ligne la Partie B (18 transactions) du test de recrutement Fiscaliste Groupe, entièrement auto-corrigée, avec consultation des résultats côté admin (scopée par filiale).

**Architecture:** 4 tables (`recruitment_tests`/`recruitment_questions`/`recruitment_candidatures`/`recruitment_reponses`), 2 services (`RecruitmentScoringService` pur, `RecruitmentService` orchestrateur), un contrôleur public (`/recrutement/*`, sans auth) et deux contrôleurs admin (liste postes + liste/détail candidatures), une commande planifiée pour clôturer les tentatives expirées (même mécanique que `events:close-due`).

**Tech Stack:** Laravel 13 / PHP 8.3, migrations + Eloquent, PHPUnit 12 (SQLite `:memory:`), assets manuels (`public/js`, `public/css`, `versioned_asset()`), Mail `ShouldQueue` + queue `database`.

## Global Constraints

- `declare(strict_types=1)` en tête de chaque fichier PHP ; services en `final`.
- Logique métier dans `App\Services\*`, jamais dans les contrôleurs.
- Enums PHP natifs avec `label()` FR.
- `recruitment_tests` cloisonné par filiale via le global scope `App\Models\Scopes\FilialeScope` (déjà utilisé par `EventType`) — no-op hors requête admin (RME-2), 404 sur route-model binding cross-filiale.
- Un fichier public JS/CSS modifié doit être servi via `versioned_asset()`, jamais `asset()` nu.
- Communication utilisateur en français, jamais de co-auteur Claude dans les commits.
- Spec de référence : `docs/superpowers/specs/2026-07-29-test-recrutement-design.md`.
- Branche : `feat/test-recrutement-en-ligne` (déjà créée).

---

### Task 1: Fondations données (migrations, enum, modèles, factories)

**Files:**
- Create: `database/migrations/2026_07_29_000001_create_recruitment_tests_table.php`
- Create: `database/migrations/2026_07_29_000002_create_recruitment_questions_table.php`
- Create: `database/migrations/2026_07_29_000003_create_recruitment_candidatures_table.php`
- Create: `database/migrations/2026_07_29_000004_create_recruitment_reponses_table.php`
- Create: `app/Enums/RecruitmentCandidatureStatus.php`
- Create: `app/Models/RecruitmentTest.php`
- Create: `app/Models/RecruitmentQuestion.php`
- Create: `app/Models/RecruitmentCandidature.php`
- Create: `app/Models/RecruitmentReponse.php`
- Create: `database/factories/RecruitmentTestFactory.php`
- Create: `database/factories/RecruitmentQuestionFactory.php`
- Test: `tests/Feature/RecruitmentDataModelTest.php`

**Interfaces:**
- Produces: `RecruitmentTest` (props `filiale_id, titre, slug, duree_minutes, instructions, actif` ; relations `filiale()`, `questions()`, `candidatures()` ; route key = `slug`), `RecruitmentQuestion` (props `recruitment_test_id, ordre, contexte:array, reponse_attendue:array, tolerance` ; relation `test()`), `RecruitmentCandidature` (props `recruitment_test_id, nom, prenom, email, telephone, token, started_at, expires_at, submitted_at, statut:RecruitmentCandidatureStatus, score` ; relations `test()`, `reponses()` ; méthode `estEnCours(): bool`), `RecruitmentReponse` (props `recruitment_candidature_id, recruitment_question_id, classement_saisi, ajustement_tva_saisi, retenue_saisie` ; relations `question()`, `candidature()`). `RecruitmentTest::factory()`, `RecruitmentQuestion::factory()`.

- [ ] **Step 1: Écrire le test (échoue, tables inexistantes)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RecruitmentCandidatureStatus;
use App\Models\Filiale;
use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentReponse;
use App\Models\RecruitmentTest;
use App\Models\Scopes\FilialeScope;
use App\Support\FilialeScoping;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecruitmentDataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_test_de_recrutement_est_cree_avec_ses_questions(): void
    {
        $test = RecruitmentTest::factory()->create(['titre' => 'Fiscaliste Groupe']);
        RecruitmentQuestion::factory()->for($test)->count(3)->create();

        $this->assertSame(3, $test->questions()->count());
        $this->assertTrue($test->questions()->first()->test->is($test));
    }

    public function test_le_slug_est_unique(): void
    {
        RecruitmentTest::factory()->create(['slug' => 'fiscaliste-groupe']);

        $this->expectException(QueryException::class);
        RecruitmentTest::factory()->create(['slug' => 'fiscaliste-groupe']);
    }

    public function test_un_email_ne_peut_avoir_quune_seule_candidature_par_test(): void
    {
        $test = RecruitmentTest::factory()->create();
        RecruitmentCandidature::create($this->candidatureAttributes($test, 'a@exemple.ci'));

        $this->expectException(QueryException::class);
        RecruitmentCandidature::create($this->candidatureAttributes($test, 'a@exemple.ci'));
    }

    public function test_le_global_scope_filiale_cloisonne_les_tests_en_contexte_admin(): void
    {
        $filialeA = Filiale::factory()->create();
        $filialeB = Filiale::factory()->create();
        RecruitmentTest::factory()->create(['filiale_id' => $filialeA->id, 'titre' => 'Poste A']);
        RecruitmentTest::factory()->create(['filiale_id' => $filialeB->id, 'titre' => 'Poste B']);

        app(FilialeScoping::class)->scopeToFiliale($filialeA->id);
        $this->assertSame(['Poste A'], RecruitmentTest::pluck('titre')->all());

        app(FilialeScoping::class)->scopeToAll();
        $this->assertCount(2, RecruitmentTest::all());
    }

    public function test_hors_contexte_admin_le_scope_est_un_no_op(): void
    {
        Filiale::factory()->count(2)->create()->each(
            fn (Filiale $filiale) => RecruitmentTest::factory()->create(['filiale_id' => $filiale->id]),
        );

        // Aucun app(FilialeScoping::class)->scopeTo...() appelé : contexte inactif (cron/public/CLI).
        $this->assertCount(2, RecruitmentTest::all());
    }

    public function test_une_reponse_stocke_les_trois_champs_saisis(): void
    {
        $test = RecruitmentTest::factory()->create();
        $question = RecruitmentQuestion::factory()->for($test)->create();
        $candidature = RecruitmentCandidature::create($this->candidatureAttributes($test, 'b@exemple.ci'));

        $reponse = RecruitmentReponse::create([
            'recruitment_candidature_id' => $candidature->id,
            'recruitment_question_id' => $question->id,
            'classement_saisi' => 'Majeur',
            'ajustement_tva_saisi' => -168000,
            'retenue_saisie' => 0,
        ]);

        $this->assertTrue($reponse->question->is($question));
        $this->assertTrue($reponse->candidature->is($candidature));
        $this->assertSame(RecruitmentCandidatureStatus::EnCours, $candidature->fresh()->statut);
    }

    /** @return array<string, mixed> */
    private function candidatureAttributes(RecruitmentTest $test, string $email): array
    {
        return [
            'recruitment_test_id' => $test->id,
            'nom' => 'Doe',
            'prenom' => 'Jane',
            'email' => $email,
            'token' => bin2hex(random_bytes(20)),
            'started_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes(45),
            'statut' => RecruitmentCandidatureStatus::EnCours,
        ];
    }
}
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=RecruitmentDataModelTest`
Expected: FAIL (classes/tables inexistantes).

- [ ] **Step 3: Créer l'enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'une candidature au test de recrutement en ligne.
 */
enum RecruitmentCandidatureStatus: string
{
    case EnCours = 'en_cours';
    case Soumis = 'soumis';
    case Expire = 'expire';

    public function label(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::Soumis => 'Soumis',
            self::Expire => 'Expiré',
        };
    }

    public function estTerminee(): bool
    {
        return $this !== self::EnCours;
    }
}
```

- [ ] **Step 4: Créer les 4 migrations**

`2026_07_29_000001_create_recruitment_tests_table.php` :

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Poste de recrutement ouvert au test en ligne. Cloisonné par filiale
 * (App\Models\Scopes\FilialeScope, même garantie que `event_types`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('filiale_id')->constrained('filiales')->restrictOnDelete();
            $table->string('titre');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('duree_minutes');
            $table->text('instructions')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_tests');
    }
};
```

`2026_07_29_000002_create_recruitment_questions_table.php` :

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Question d'un test (une transaction du cas Fiscaliste, par ex.). `contexte` =
 * données affichées au candidat ; `reponse_attendue` = corrigé, jamais exposé
 * côté public (voir App\Http\Controllers\Public\RecruitmentController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_test_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('ordre');
            $table->json('contexte');
            $table->json('reponse_attendue');
            $table->unsignedInteger('tolerance')->default(0);
            $table->timestamps();

            $table->unique(['recruitment_test_id', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_questions');
    }
};
```

`2026_07_29_000003_create_recruitment_candidatures_table.php` :

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tentative d'un candidat sur un test (identité déclarée, pas de compte).
 * `token` : clé d'URL opaque, jamais un identifiant de session. Un seul essai
 * par email et par poste (contrainte unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_candidatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_test_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('prenom');
            $table->string('email');
            $table->string('telephone')->nullable();
            $table->string('token', 64)->unique();
            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->dateTime('submitted_at')->nullable();
            $table->string('statut', 20)->default('en_cours'); // App\Enums\RecruitmentCandidatureStatus
            $table->unsignedSmallInteger('score')->nullable();
            $table->timestamps();

            $table->unique(['recruitment_test_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_candidatures');
    }
};
```

`2026_07_29_000004_create_recruitment_reponses_table.php` :

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réponse d'un candidat à une question (3 champs comparés au corrigé par
 * App\Services\RecruitmentScoringService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_reponses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_candidature_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recruitment_question_id')->constrained()->cascadeOnDelete();
            $table->string('classement_saisi', 20)->nullable();
            $table->bigInteger('ajustement_tva_saisi')->nullable();
            $table->bigInteger('retenue_saisie')->nullable();
            $table->timestamps();

            $table->unique(['recruitment_candidature_id', 'recruitment_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_reponses');
    }
};
```

- [ ] **Step 5: Créer les 4 modèles**

`app/Models/RecruitmentTest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\FilialeScope;
use Database\Factories\RecruitmentTestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Poste de recrutement ouvert au test en ligne, cloisonné par filiale
 * ({@see FilialeScope}, même garantie que {@see EventType}) : no-op hors
 * requête admin (RME-2), 404 sur une autre filiale au route-model binding.
 *
 * @property int $id
 * @property int $filiale_id
 * @property string $titre
 * @property string $slug
 * @property int $duree_minutes
 * @property ?string $instructions
 * @property bool $actif
 */
class RecruitmentTest extends Model
{
    /** @use HasFactory<RecruitmentTestFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['filiale_id', 'titre', 'slug', 'duree_minutes', 'instructions', 'actif'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new FilialeScope);
    }

    /** @return BelongsTo<Filiale, $this> */
    public function filiale(): BelongsTo
    {
        return $this->belongsTo(Filiale::class);
    }

    /** @return HasMany<RecruitmentQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(RecruitmentQuestion::class);
    }

    /** @return HasMany<RecruitmentCandidature, $this> */
    public function candidatures(): HasMany
    {
        return $this->hasMany(RecruitmentCandidature::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
```

`app/Models/RecruitmentQuestion.php` :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RecruitmentQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Question d'un test de recrutement (une transaction du cas, par exemple).
 * `contexte` = données affichées au candidat. `reponse_attendue` = corrigé,
 * jamais chargé côté contrôleur public.
 *
 * @property int $id
 * @property int $recruitment_test_id
 * @property int $ordre
 * @property array{id: string, entite: string, date: string, piece: string, tiers: string, nature: string, montant_ht: int, tva_comptabilisee: int, retenue_operee: int, informations: string} $contexte
 * @property array{classement: string, ajustement_tva: int, retenue_attendue: int, explication: string} $reponse_attendue
 * @property int $tolerance
 */
class RecruitmentQuestion extends Model
{
    /** @use HasFactory<RecruitmentQuestionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['recruitment_test_id', 'ordre', 'contexte', 'reponse_attendue', 'tolerance'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['contexte' => 'array', 'reponse_attendue' => 'array'];
    }

    /** @return BelongsTo<RecruitmentTest, $this> */
    public function test(): BelongsTo
    {
        return $this->belongsTo(RecruitmentTest::class, 'recruitment_test_id');
    }
}
```

`app/Models/RecruitmentCandidature.php` :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecruitmentCandidatureStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tentative d'un candidat sur un test de recrutement (identité déclarée, pas
 * de compte). `token` : clé d'URL opaque, jamais un identifiant de session.
 *
 * @property int $id
 * @property int $recruitment_test_id
 * @property string $nom
 * @property string $prenom
 * @property string $email
 * @property ?string $telephone
 * @property string $token
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property ?Carbon $submitted_at
 * @property RecruitmentCandidatureStatus $statut
 * @property ?int $score
 */
class RecruitmentCandidature extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'recruitment_test_id', 'nom', 'prenom', 'email', 'telephone',
        'token', 'started_at', 'expires_at', 'submitted_at', 'statut', 'score',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'statut' => RecruitmentCandidatureStatus::class,
        ];
    }

    /** @return BelongsTo<RecruitmentTest, $this> */
    public function test(): BelongsTo
    {
        return $this->belongsTo(RecruitmentTest::class, 'recruitment_test_id');
    }

    /** @return HasMany<RecruitmentReponse, $this> */
    public function reponses(): HasMany
    {
        return $this->hasMany(RecruitmentReponse::class);
    }

    /** Encore active : ni terminée, ni hors délai (le serveur fait foi, jamais le client). */
    public function estEnCours(): bool
    {
        return $this->statut === RecruitmentCandidatureStatus::EnCours
            && Carbon::now()->lessThan($this->expires_at);
    }
}
```

`app/Models/RecruitmentReponse.php` :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réponse d'un candidat à une question (3 champs saisis), comparée au corrigé
 * de la question par {@see \App\Services\RecruitmentScoringService}.
 *
 * @property int $id
 * @property int $recruitment_candidature_id
 * @property int $recruitment_question_id
 * @property ?string $classement_saisi
 * @property ?int $ajustement_tva_saisi
 * @property ?int $retenue_saisie
 */
class RecruitmentReponse extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'recruitment_candidature_id', 'recruitment_question_id',
        'classement_saisi', 'ajustement_tva_saisi', 'retenue_saisie',
    ];

    /** @return BelongsTo<RecruitmentQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(RecruitmentQuestion::class, 'recruitment_question_id');
    }

    /** @return BelongsTo<RecruitmentCandidature, $this> */
    public function candidature(): BelongsTo
    {
        return $this->belongsTo(RecruitmentCandidature::class, 'recruitment_candidature_id');
    }
}
```

- [ ] **Step 6: Créer les factories**

`database/factories/RecruitmentTestFactory.php` :

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Filiale;
use App\Models\RecruitmentTest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecruitmentTest>
 */
class RecruitmentTestFactory extends Factory
{
    protected $model = RecruitmentTest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $titre = 'Poste '.fake()->unique()->jobTitle();

        return [
            'filiale_id' => Filiale::factory(),
            'titre' => $titre,
            'slug' => Str::slug($titre).'-'.Str::lower(Str::random(4)),
            'duree_minutes' => 45,
            'instructions' => 'Répondez aux 18 transactions ci-dessous.',
            'actif' => true,
        ];
    }
}
```

`database/factories/RecruitmentQuestionFactory.php` :

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentQuestion>
 */
class RecruitmentQuestionFactory extends Factory
{
    protected $model = RecruitmentQuestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recruitment_test_id' => RecruitmentTest::factory(),
            'ordre' => fake()->unique()->numberBetween(1, 1000),
            'contexte' => [
                'id' => 'T'.fake()->unique()->numberBetween(1, 999),
                'entite' => 'Kôrô Technologies',
                'date' => '2026-06-03',
                'piece' => 'FV-601',
                'tiers' => 'Client Test',
                'nature' => 'Vente de licence',
                'montant_ht' => 1000000,
                'tva_comptabilisee' => 180000,
                'retenue_operee' => 0,
                'informations' => 'Contrat et facture disponibles',
            ],
            'reponse_attendue' => [
                'classement' => 'Conforme',
                'ajustement_tva' => 0,
                'retenue_attendue' => 0,
                'explication' => 'TVA collectée à rapprocher',
            ],
            'tolerance' => 0,
        ];
    }
}
```

- [ ] **Step 7: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=RecruitmentDataModelTest`
Expected: PASS (5 tests).

- [ ] **Step 8: Pint + commit**

```bash
./vendor/bin/pint app/Models/Recruitment*.php app/Enums/RecruitmentCandidatureStatus.php database/migrations/2026_07_29_* database/factories/Recruitment*.php
git add app/Models/Recruitment*.php app/Enums/RecruitmentCandidatureStatus.php database/migrations/2026_07_29_0000{1,2,3,4}* database/factories/Recruitment*.php tests/Feature/RecruitmentDataModelTest.php
git commit -m "feat(recrutement): schéma de données (tests/questions/candidatures/réponses)"
```

---

### Task 2: `RecruitmentScoringService` (notation pure)

**Files:**
- Create: `app/Services/RecruitmentScoringService.php`
- Test: `tests/Unit/RecruitmentScoringServiceTest.php`

**Interfaces:**
- Consumes: `RecruitmentQuestion` (`reponse_attendue`, `tolerance`), `RecruitmentReponse` (`classement_saisi`, `ajustement_tva_saisi`, `retenue_saisie`) — Task 1.
- Produces: `RecruitmentScoringService::scoreReponse(RecruitmentQuestion $question, RecruitmentReponse $reponse): array{classement: bool, ajustement_tva: bool, retenue_attendue: bool, points: int}` et `scoreCandidature(RecruitmentCandidature $candidature): int` — utilisés par Task 3.

- [ ] **Step 1: Écrire le test (échoue, classe inexistante)**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentReponse;
use App\Models\RecruitmentTest;
use App\Services\RecruitmentScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecruitmentScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecruitmentScoringService $scoring;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoring = new RecruitmentScoringService;
    }

    private function question(array $reponseAttendue, int $tolerance = 0): RecruitmentQuestion
    {
        return RecruitmentQuestion::factory()->create([
            'reponse_attendue' => $reponseAttendue,
            'tolerance' => $tolerance,
        ]);
    }

    public function test_une_reponse_totalement_correcte_vaut_3_points(): void
    {
        $question = $this->question(['classement' => 'Majeur', 'ajustement_tva' => -168000, 'retenue_attendue' => 0, 'explication' => '']);
        $reponse = new RecruitmentReponse([
            'classement_saisi' => 'Majeur', 'ajustement_tva_saisi' => -168000, 'retenue_saisie' => 0,
        ]);

        $result = $this->scoring->scoreReponse($question, $reponse);

        $this->assertSame(['classement' => true, 'ajustement_tva' => true, 'retenue_attendue' => true, 'points' => 3], $result);
    }

    public function test_une_reponse_vide_vaut_0_point(): void
    {
        $question = $this->question(['classement' => 'Majeur', 'ajustement_tva' => -168000, 'retenue_attendue' => 0, 'explication' => '']);
        $reponse = new RecruitmentReponse;

        $result = $this->scoring->scoreReponse($question, $reponse);

        $this->assertSame(0, $result['points']);
    }

    public function test_la_tolerance_numerique_est_appliquee(): void
    {
        $question = $this->question(['classement' => 'Majeur', 'ajustement_tva' => -168000, 'retenue_attendue' => 0, 'explication' => ''], tolerance: 500);
        $reponse = new RecruitmentReponse([
            'classement_saisi' => 'Majeur', 'ajustement_tva_saisi' => -168300, 'retenue_saisie' => 0,
        ]);

        $result = $this->scoring->scoreReponse($question, $reponse);

        $this->assertTrue($result['ajustement_tva']);
    }

    public function test_score_candidature_additionne_les_points_de_toutes_les_reponses(): void
    {
        $test = RecruitmentTest::factory()->create();
        $q1 = RecruitmentQuestion::factory()->for($test)->create([
            'reponse_attendue' => ['classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0, 'explication' => ''],
        ]);
        $q2 = RecruitmentQuestion::factory()->for($test)->create([
            'reponse_attendue' => ['classement' => 'Critique', 'ajustement_tva' => 0, 'retenue_attendue' => 1960000, 'explication' => ''],
        ]);
        $candidature = RecruitmentCandidature::create([
            'recruitment_test_id' => $test->id, 'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'x@exemple.ci',
            'token' => bin2hex(random_bytes(20)), 'started_at' => Carbon::now(), 'expires_at' => Carbon::now()->addMinutes(45),
            'statut' => \App\Enums\RecruitmentCandidatureStatus::EnCours,
        ]);
        RecruitmentReponse::create([
            'recruitment_candidature_id' => $candidature->id, 'recruitment_question_id' => $q1->id,
            'classement_saisi' => 'Conforme', 'ajustement_tva_saisi' => 0, 'retenue_saisie' => 0,
        ]);
        RecruitmentReponse::create([
            'recruitment_candidature_id' => $candidature->id, 'recruitment_question_id' => $q2->id,
            'classement_saisi' => 'Critique', 'ajustement_tva_saisi' => 0, 'retenue_saisie' => 0, // retenue fausse
        ]);

        $this->assertSame(5, $this->scoring->scoreCandidature($candidature)); // 3 + 2
    }
}
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=RecruitmentScoringServiceTest`
Expected: FAIL (classe `RecruitmentScoringService` inexistante).

- [ ] **Step 3: Implémenter**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentReponse;

/**
 * Notation pure de la Partie B du test de recrutement : 3 champs comparés au
 * corrigé (`reponse_attendue`) par question, jamais de dépendance à une requête
 * HTTP ou à l'horloge — testable isolément.
 */
final class RecruitmentScoringService
{
    /**
     * @return array{classement: bool, ajustement_tva: bool, retenue_attendue: bool, points: int}
     */
    public function scoreReponse(RecruitmentQuestion $question, RecruitmentReponse $reponse): array
    {
        $attendu = $question->reponse_attendue;
        $tolerance = $question->tolerance;

        $classementOk = $reponse->classement_saisi !== null
            && $reponse->classement_saisi === ($attendu['classement'] ?? null);
        $tvaOk = $this->withinTolerance($reponse->ajustement_tva_saisi, $attendu['ajustement_tva'] ?? null, $tolerance);
        $retenueOk = $this->withinTolerance($reponse->retenue_saisie, $attendu['retenue_attendue'] ?? null, $tolerance);

        return [
            'classement' => $classementOk,
            'ajustement_tva' => $tvaOk,
            'retenue_attendue' => $retenueOk,
            'points' => (int) $classementOk + (int) $tvaOk + (int) $retenueOk,
        ];
    }

    /** Score total d'une candidature = somme des points sur toutes ses réponses. */
    public function scoreCandidature(RecruitmentCandidature $candidature): int
    {
        $total = 0;
        foreach ($candidature->reponses()->with('question')->get() as $reponse) {
            $total += $this->scoreReponse($reponse->question, $reponse)['points'];
        }

        return $total;
    }

    private function withinTolerance(?int $saisi, ?int $attendu, int $tolerance): bool
    {
        if ($saisi === null || $attendu === null) {
            return false;
        }

        return abs($saisi - $attendu) <= $tolerance;
    }
}
```

- [ ] **Step 4: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=RecruitmentScoringServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Services/RecruitmentScoringService.php
git add app/Services/RecruitmentScoringService.php tests/Unit/RecruitmentScoringServiceTest.php
git commit -m "feat(recrutement): service de notation pure (RecruitmentScoringService)"
```

---

### Task 3: `RecruitmentService` (orchestration candidature) + notification email des managers

> Fusionné avec la notification email (initialement prévue en Task 8) : `RecruitmentService::submit()`/`closeExpired()` instancient `RecruitmentCandidatureSubmittedMail` directement — les deux ne peuvent pas être des tâches indépendamment révisables, l'une ne compile/teste pas sans l'autre.

**Files:**
- Create: `app/Mail/RecruitmentCandidatureSubmittedMail.php`
- Create: `resources/views/emails/recruitment/candidature-submitted.blade.php`
- Create: `app/Services/RecruitmentService.php`
- Test: `tests/Feature/RecruitmentServiceTest.php`
- Test: `tests/Feature/RecruitmentNotificationTest.php`

**Interfaces:**
- Consumes: `RecruitmentScoringService::scoreCandidature()` (Task 2) ; modèles Task 1.
- Produces: `RecruitmentService::startCandidature(RecruitmentTest $test, array $identite): ?RecruitmentCandidature` (`null` si email déjà utilisé), `saveReponse(RecruitmentCandidature $candidature, RecruitmentQuestion $question, array $data): ?RecruitmentReponse` (`null` si session close/expirée), `submit(RecruitmentCandidature $candidature): ?RecruitmentCandidature` (`null` si déjà terminée), `closeExpired(): int` — tous consommés par Task 5 (contrôleur public) et Task 7 (commande planifiée). `RecruitmentCandidatureSubmittedMail` — aucune interface consommée par une tâche ultérieure.

- [ ] **Step 1: Écrire le test (échoue, classe inexistante)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RecruitmentCandidatureStatus;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentTest;
use App\Services\RecruitmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecruitmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecruitmentService $recruitment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recruitment = app(RecruitmentService::class);
    }

    private function identite(string $email = 'candidat@exemple.ci'): array
    {
        return ['nom' => 'Doe', 'prenom' => 'Jane', 'email' => $email, 'telephone' => null];
    }

    public function test_demarrer_une_candidature_pose_started_at_et_expires_at(): void
    {
        $test = RecruitmentTest::factory()->create(['duree_minutes' => 45]);

        $candidature = $this->recruitment->startCandidature($test, $this->identite());

        $this->assertNotNull($candidature);
        $this->assertSame(RecruitmentCandidatureStatus::EnCours, $candidature->statut);
        $this->assertEqualsWithDelta(45, $candidature->started_at->diffInMinutes($candidature->expires_at), 0.01);
    }

    public function test_un_meme_email_ne_peut_pas_redemarrer_le_meme_test(): void
    {
        $test = RecruitmentTest::factory()->create();
        $this->recruitment->startCandidature($test, $this->identite());

        $second = $this->recruitment->startCandidature($test, $this->identite());

        $this->assertNull($second);
        $this->assertSame(1, $test->candidatures()->count());
    }

    public function test_sauvegarder_une_reponse_pendant_le_delai(): void
    {
        $test = RecruitmentTest::factory()->create();
        $question = RecruitmentQuestion::factory()->for($test)->create();
        $candidature = $this->recruitment->startCandidature($test, $this->identite());

        $reponse = $this->recruitment->saveReponse($candidature, $question, [
            'classement' => 'Majeur', 'ajustement_tva' => -168000, 'retenue_attendue' => 0,
        ]);

        $this->assertNotNull($reponse);
        $this->assertSame('Majeur', $reponse->classement_saisi);
    }

    public function test_sauvegarder_une_reponse_apres_expiration_echoue(): void
    {
        $test = RecruitmentTest::factory()->create();
        $question = RecruitmentQuestion::factory()->for($test)->create();
        $candidature = $this->recruitment->startCandidature($test, $this->identite());
        $candidature->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

        $reponse = $this->recruitment->saveReponse($candidature, $question, ['classement' => 'Majeur']);

        $this->assertNull($reponse);
    }

    public function test_soumettre_calcule_le_score_et_marque_soumis(): void
    {
        $test = RecruitmentTest::factory()->create();
        $question = RecruitmentQuestion::factory()->for($test)->create([
            'reponse_attendue' => ['classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0, 'explication' => ''],
        ]);
        $candidature = $this->recruitment->startCandidature($test, $this->identite());
        $this->recruitment->saveReponse($candidature, $question, [
            'classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0,
        ]);

        $result = $this->recruitment->submit($candidature);

        $this->assertNotNull($result);
        $this->assertSame(RecruitmentCandidatureStatus::Soumis, $result->statut);
        $this->assertSame(3, $result->score);
        $this->assertNotNull($result->submitted_at);
    }

    public function test_soumettre_deux_fois_echoue_la_seconde_fois(): void
    {
        $test = RecruitmentTest::factory()->create();
        $candidature = $this->recruitment->startCandidature($test, $this->identite());
        $this->recruitment->submit($candidature);

        $second = $this->recruitment->submit($candidature->fresh());

        $this->assertNull($second);
    }

    public function test_close_expired_cloture_et_note_les_candidatures_hors_delai(): void
    {
        $test = RecruitmentTest::factory()->create();
        $question = RecruitmentQuestion::factory()->for($test)->create([
            'reponse_attendue' => ['classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0, 'explication' => ''],
        ]);
        $candidature = $this->recruitment->startCandidature($test, $this->identite());
        $this->recruitment->saveReponse($candidature, $question, ['classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0]);
        $candidature->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

        $count = $this->recruitment->closeExpired();

        $this->assertSame(1, $count);
        $candidature->refresh();
        $this->assertSame(RecruitmentCandidatureStatus::Expire, $candidature->statut);
        $this->assertSame(3, $candidature->score);

        // Idempotent : un deuxième passage ne reclôture rien.
        $this->assertSame(0, $this->recruitment->closeExpired());
    }
}
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=RecruitmentServiceTest`
Expected: FAIL (classe `RecruitmentService` inexistante).

- [ ] **Step 3: Créer le mailable de notification (requis par `RecruitmentService`, écrit juste après)**

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\RecruitmentCandidature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifie un manager (AdminFiliale de la filiale du poste, ou tout SuperAdmin)
 * qu'une candidature de recrutement est terminée (soumise ou expirée) et notée.
 */
class RecruitmentCandidatureSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly RecruitmentCandidature $candidature) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Candidature reçue — '.$this->candidature->test->titre,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recruitment.candidature-submitted');
    }
}
```

`resources/views/emails/recruitment/candidature-submitted.blade.php` :

```blade
<p>Une candidature vient d'être {{ $candidature->statut->label() }} pour le poste « {{ $candidature->test->titre }} ».</p>
<ul>
    <li>Candidat : {{ $candidature->prenom }} {{ $candidature->nom }} ({{ $candidature->email }})</li>
    <li>Score : {{ $candidature->score }} / {{ $candidature->test->questions()->count() * 3 }}</li>
</ul>
<p>Consultez le détail dans Presence, rubrique Recrutement.</p>
```

- [ ] **Step 4: Implémenter `RecruitmentService`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RecruitmentCandidatureStatus;
use App\Enums\UserRole;
use App\Mail\RecruitmentCandidatureSubmittedMail;
use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentReponse;
use App\Models\RecruitmentTest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Orchestration d'une candidature au test de recrutement en ligne. Renvoie
 * `null` (jamais d'exception) quand une précondition métier échoue — le
 * contrôleur public traduit `null` en réponse HTTP adaptée (422/410).
 */
final class RecruitmentService
{
    public function __construct(private readonly RecruitmentScoringService $scoring) {}

    /**
     * @param  array{nom: string, prenom: string, email: string, telephone: ?string}  $identite
     */
    public function startCandidature(RecruitmentTest $test, array $identite): ?RecruitmentCandidature
    {
        $exists = RecruitmentCandidature::where('recruitment_test_id', $test->id)
            ->where('email', $identite['email'])
            ->exists();

        if ($exists) {
            return null;
        }

        return RecruitmentCandidature::create([
            'recruitment_test_id' => $test->id,
            'nom' => $identite['nom'],
            'prenom' => $identite['prenom'],
            'email' => $identite['email'],
            'telephone' => $identite['telephone'] ?? null,
            'token' => Str::random(40),
            'started_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes($test->duree_minutes),
            'statut' => RecruitmentCandidatureStatus::EnCours,
        ]);
    }

    /**
     * @param  array{classement?: ?string, ajustement_tva?: ?int, retenue_attendue?: ?int}  $data
     */
    public function saveReponse(RecruitmentCandidature $candidature, RecruitmentQuestion $question, array $data): ?RecruitmentReponse
    {
        if (! $candidature->estEnCours()) {
            return null;
        }

        return RecruitmentReponse::updateOrCreate(
            [
                'recruitment_candidature_id' => $candidature->id,
                'recruitment_question_id' => $question->id,
            ],
            [
                'classement_saisi' => $data['classement'] ?? null,
                'ajustement_tva_saisi' => $data['ajustement_tva'] ?? null,
                'retenue_saisie' => $data['retenue_attendue'] ?? null,
            ],
        );
    }

    public function submit(RecruitmentCandidature $candidature): ?RecruitmentCandidature
    {
        if ($candidature->statut !== RecruitmentCandidatureStatus::EnCours) {
            return null;
        }

        $candidature->forceFill([
            'statut' => RecruitmentCandidatureStatus::Soumis,
            'submitted_at' => Carbon::now(),
            'score' => $this->scoring->scoreCandidature($candidature),
        ])->save();

        $this->notifyManagers($candidature);

        return $candidature;
    }

    /**
     * Clôture et note toute candidature `en_cours` dont le délai est dépassé.
     * Idempotente : verrou par ligne (comme {@see \App\Console\Commands\CloseDueEvents}),
     * un deuxième passage sur une candidature déjà traitée est un no-op.
     */
    public function closeExpired(): int
    {
        $count = 0;

        $expired = RecruitmentCandidature::where('statut', RecruitmentCandidatureStatus::EnCours)
            ->where('expires_at', '<', Carbon::now())
            ->get();

        foreach ($expired as $candidature) {
            $justClosed = DB::transaction(function () use ($candidature): bool {
                $locked = RecruitmentCandidature::whereKey($candidature->id)->lockForUpdate()->first();

                if ($locked === null || $locked->statut !== RecruitmentCandidatureStatus::EnCours) {
                    return false;
                }

                $locked->forceFill([
                    'statut' => RecruitmentCandidatureStatus::Expire,
                    'submitted_at' => Carbon::now(),
                    'score' => $this->scoring->scoreCandidature($locked),
                ])->save();

                return true;
            });

            if ($justClosed) {
                $count++;
                $this->notifyManagers($candidature->fresh());
            }
        }

        return $count;
    }

    /** Notifie les managers (AdminFiliale de la filiale du poste + tout SuperAdmin). */
    private function notifyManagers(RecruitmentCandidature $candidature): void
    {
        $test = $candidature->test;

        $managers = User::where(function ($query) use ($test): void {
            $query->where('filiale_id', $test->filiale_id)->where('role', UserRole::AdminFiliale);
        })->orWhere('role', UserRole::SuperAdmin)->get();

        foreach ($managers as $manager) {
            Mail::to($manager->email)->queue(new RecruitmentCandidatureSubmittedMail($candidature));
        }
    }
}
```

> `submit()`/`closeExpired()` appellent `Mail::to(...)->queue(...)` : ajouter `use Illuminate\Support\Facades\Mail;` et `Mail::fake();` en première ligne de `setUp()` dans `RecruitmentServiceTest` pour éviter tout envoi réel pendant ce test (la notification elle-même est vérifiée en détail par `RecruitmentNotificationTest`, Step 6 ci-dessous).

- [ ] **Step 5: Lancer `RecruitmentServiceTest`, vérifier le succès**

Run: `php artisan test --filter=RecruitmentServiceTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Écrire le test de notification (échoue si la logique de ciblage des managers est absente)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\RecruitmentCandidatureSubmittedMail;
use App\Models\Filiale;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentTest;
use App\Models\User;
use App\Services\RecruitmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RecruitmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_soumettre_notifie_ladminfiliale_de_la_filiale_et_le_superadmin_mais_pas_une_autre_filiale(): void
    {
        Mail::fake();

        $filialeA = Filiale::factory()->create();
        $filialeB = Filiale::factory()->create();
        $adminA = User::factory()->filialeAdmin()->forFiliale($filialeA)->create();
        $adminB = User::factory()->filialeAdmin()->forFiliale($filialeB)->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $test = RecruitmentTest::factory()->create(['filiale_id' => $filialeA->id]);
        RecruitmentQuestion::factory()->for($test)->create();
        $recruitment = app(RecruitmentService::class);
        $candidature = $recruitment->startCandidature($test, [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'x@exemple.ci', 'telephone' => null,
        ]);

        $recruitment->submit($candidature);

        Mail::assertQueued(RecruitmentCandidatureSubmittedMail::class, fn ($mail) => $mail->hasTo($adminA->email));
        Mail::assertQueued(RecruitmentCandidatureSubmittedMail::class, fn ($mail) => $mail->hasTo($superAdmin->email));
        Mail::assertNotQueued(RecruitmentCandidatureSubmittedMail::class, fn ($mail) => $mail->hasTo($adminB->email));
    }
}
```

- [ ] **Step 7: Lancer le test de notification, vérifier le succès**

Run: `php artisan test --filter=RecruitmentNotificationTest`
Expected: PASS (1 test).

- [ ] **Step 8: Pint + commit**

```bash
./vendor/bin/pint app/Mail/RecruitmentCandidatureSubmittedMail.php app/Services/RecruitmentService.php
git add app/Mail/RecruitmentCandidatureSubmittedMail.php resources/views/emails/recruitment app/Services/RecruitmentService.php tests/Feature/RecruitmentServiceTest.php tests/Feature/RecruitmentNotificationTest.php
git commit -m "feat(recrutement): orchestration des candidatures + notification email des managers"
```

---

### Task 4: Seeder du contenu Fiscaliste (18 transactions + corrigé)

**Files:**
- Create: `database/seeders/RecruitmentFiscalisteSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/RecruitmentFiscalisteSeederTest.php`

**Interfaces:**
- Consumes: `RecruitmentTest`, `RecruitmentQuestion` (Task 1), `Filiale::defaultId()` (existant).
- Produces: un `RecruitmentTest` (`slug = 'fiscaliste-groupe'`) avec 18 `RecruitmentQuestion` — consommé par Task 5/6/9/10 (parcours candidat + admin) et par la démo manuelle.

- [ ] **Step 1: Écrire le test (échoue, seeder inexistant)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RecruitmentTest;
use Database\Seeders\RecruitmentFiscalisteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecruitmentFiscalisteSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_seeder_cree_le_poste_fiscaliste_avec_18_questions(): void
    {
        (new RecruitmentFiscalisteSeeder)->run();

        $test = RecruitmentTest::where('slug', 'fiscaliste-groupe')->firstOrFail();
        $this->assertSame(18, $test->questions()->count());
        $this->assertSame(45, $test->duree_minutes);
    }

    public function test_relancer_le_seeder_ne_duplique_pas(): void
    {
        (new RecruitmentFiscalisteSeeder)->run();
        (new RecruitmentFiscalisteSeeder)->run();

        $this->assertSame(1, RecruitmentTest::where('slug', 'fiscaliste-groupe')->count());
    }

    public function test_une_candidature_parfaite_obtient_54_sur_54(): void
    {
        (new RecruitmentFiscalisteSeeder)->run();
        $test = RecruitmentTest::where('slug', 'fiscaliste-groupe')->firstOrFail();

        $recruitment = app(\App\Services\RecruitmentService::class);
        $candidature = $recruitment->startCandidature($test, [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'parfait@exemple.ci', 'telephone' => null,
        ]);

        foreach ($test->questions as $question) {
            $attendu = $question->reponse_attendue;
            $recruitment->saveReponse($candidature, $question, [
                'classement' => $attendu['classement'],
                'ajustement_tva' => $attendu['ajustement_tva'],
                'retenue_attendue' => $attendu['retenue_attendue'],
            ]);
        }

        $result = $recruitment->submit($candidature);

        $this->assertSame(54, $result->score);
    }
}
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=RecruitmentFiscalisteSeederTest`
Expected: FAIL (classe `RecruitmentFiscalisteSeeder` inexistante).

- [ ] **Step 3: Implémenter le seeder**

Contenu source : `04_Classeur_Maitre_Confidentiel.xlsx` (onglet `Corrigé CONFIDENTIEL`, croisé avec l'onglet `Transactions` du classeur candidat pour le contexte).

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Filiale;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentTest;
use Illuminate\Database\Seeder;

/**
 * Contenu du test Fiscaliste Groupe (V1 : Partie B seule, 18 transactions).
 * Source : 04_Classeur_Maitre_Confidentiel.xlsx (onglet Corrigé CONFIDENTIEL),
 * croisé avec l'onglet Transactions du classeur candidat pour le contexte
 * affiché. `reponse_attendue` ne doit JAMAIS être chargé côté public.
 */
class RecruitmentFiscalisteSeeder extends Seeder
{
    public function run(): void
    {
        $test = RecruitmentTest::firstOrCreate(
            ['slug' => 'fiscaliste-groupe'],
            [
                'filiale_id' => Filiale::defaultId(),
                'titre' => 'Fiscaliste Groupe',
                'duree_minutes' => 45,
                'instructions' => 'Cas KÔRÔ HOLDING — juin N. Pour chacune des 18 transactions, indiquez le classement, l’ajustement de TVA (par rapport au taux d’épreuve de 18 %) et la retenue attendue.',
                'actif' => true,
            ],
        );

        if ($test->questions()->exists()) {
            return;
        }

        foreach (self::transactions() as $ordre => $row) {
            RecruitmentQuestion::create([
                'recruitment_test_id' => $test->id,
                'ordre' => $ordre + 1,
                'contexte' => [
                    'id' => $row[0],
                    'entite' => $row[1],
                    'date' => $row[2],
                    'piece' => $row[3],
                    'tiers' => $row[4],
                    'nature' => $row[5],
                    'montant_ht' => $row[6],
                    'tva_comptabilisee' => $row[7],
                    'retenue_operee' => $row[8],
                    'informations' => $row[9],
                ],
                'reponse_attendue' => [
                    'classement' => $row[10],
                    'ajustement_tva' => $row[11],
                    'retenue_attendue' => $row[12],
                    'explication' => $row[13],
                ],
                'tolerance' => 0,
            ]);
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int, 7: int, 8: int, 9: string, 10: string, 11: int, 12: int, 13: string}>
     */
    private static function transactions(): array
    {
        return [
            ['T001', 'Kôrô Technologies', '2026-06-03', 'FV-601', 'Client Lagune', 'Vente de licence', 12500000, 2250000, 0, 'Contrat, facture et PV disponibles', 'Conforme', 0, 0, 'TVA collectée à rapprocher'],
            ['T002', 'Kôrô BTP', '2026-06-04', 'FA-882', 'Métal Ivoire', 'Achat matériaux', 8400000, 1680000, 0, 'Facture : HT 8 400 000 ; TVA affichée 1 680 000', 'Majeur', -168000, 0, 'TVA admise 1 512 000 ; excès 168 000'],
            ['T003', 'Kôrô Technologies', '2026-06-05', 'FA-214', 'Conseil Plus SARL', 'Mission organisation', 3200000, 576000, 0, 'Facture et contrat ; preuve de service absente', 'Modéré', -576000, 0, "Suspendre jusqu'à preuve de service"],
            ['T004', 'Kôrô Holding', '2026-06-06', 'FA-017', 'Auto Prestige', 'Entretien véhicule dirigeant', 1750000, 315000, 0, 'Note interne : usage personnel majoritaire', 'Majeur', -315000, 0, 'Dépense personnelle'],
            ['T005', 'Kôrô Technologies', '2026-06-08', 'HC-095', 'M. Kadio', 'Conseil cybersécurité', 4600000, 0, 230000, 'Consultant résident personne physique ; prestation validée', 'Majeur', 0, 345000, 'R-1 7,5 % ; insuffisance 115 000'],
            ['T006', 'Kôrô Technologies', '2026-06-09', 'INV-74', 'Cloud Meridian Ltd', 'Hébergement cloud', 9800000, 0, 0, 'Prestataire non-résident ; aucune preuve conventionnelle', 'Critique', 0, 1960000, 'R-2 20 %, réserve convention'],
            ['T007', 'Kôrô BTP', '2026-06-10', 'AV-033', 'Client Pont Sud', 'Avoir sur travaux', -2100000, -378000, 0, "Avoir reçu mais non intégré à la déclaration préparée", 'Modéré', -378000, 0, "Intégrer l'avoir"],
            ['T008', 'Kôrô BTP', '2026-06-11', 'FA-455', 'Béton Express', 'Achat béton', 6700000, 1206000, 0, "Même numéro, montant et fournisseur que l'écriture du 30 mai", 'Majeur', -1206000, 0, 'Doublon probable'],
            ['T009', 'Kôrô Technologies', '2026-06-12', 'FA-991', 'Data Equip', 'Serveur', 15000000, 2700000, 0, 'Facture disponible ; PV de réception et mise en service absents', 'Modéré', -2700000, 0, 'Suspension documentaire'],
            ['T010', 'Kôrô Immobilier', '2026-06-13', 'BAIL-12', 'Mme Assi', 'Loyer bureaux', 6000000, 0, 0, 'Bailleur personne physique ; loyer payé intégralement', 'Majeur', 0, 900000, 'R-3 15 %'],
            ['T011', 'Kôrô Holding', '2026-06-14', 'FA-119', 'Papeterie Centre', 'Fournitures', 450000, 81000, 0, 'Facture régulière, livraison et usage professionnel', 'Conforme', 0, 0, 'Déduction admise'],
            ['T012', 'Kôrô BTP', '2026-06-15', 'FA-620', 'Élec Service', 'Maintenance', 2300000, 414000, 0, 'Facture sans NIF fournisseur', 'Majeur', -414000, 0, 'NIF absent'],
            ['T013', 'Kôrô Holding', '2026-06-16', 'AC-041', 'Stratégie Afrique', 'Acompte mission', 12000000, 2160000, 0, 'Paiement effectué ; contrat non signé ; service non commencé', 'Critique', -2160000, 0, 'Service non commencé, contrat non signé'],
            ['T014', 'Kôrô Immobilier', '2026-06-18', 'FA-308', 'Fournisseur Zénith', 'Opération signalée exonérée', 5100000, 918000, 0, 'Mention « exonéré » sur la pièce mais TVA comptabilisée', 'Majeur', -918000, 0, "TVA non admise dans l'épreuve"],
            ['T015', 'Kôrô Technologies', '2026-06-20', 'FA-711', 'Audit Systems', 'Audit technique', 3900000, 702000, 0, 'Facture datée mai, reçue et comptabilisée en juin', 'Modéré', 0, 0, 'Vérifier période/exigibilité'],
            ['T016', 'Kôrô BTP', '2026-06-22', 'FV-633', 'Client Route Est', 'Situation de travaux', 22000000, 3960000, 0, 'Facture, attachement et validation disponibles', 'Conforme', 0, 0, 'TVA collectée'],
            ['T017', 'Kôrô Technologies', '2026-06-23', 'HC-101', 'Mme Naya', 'Formation', 2800000, 0, 210000, 'Retenue opérée correctement ; non reversée à la date du 25 juillet', 'Critique', 0, 210000, 'Retenue exacte mais non reversée'],
            ['T018', 'Kôrô BTP', '2026-06-25', 'FA-970', 'Géo Expert', 'Étude de sol', 7200000, 1296000, 0, 'Facture libellée à Kôrô Immobilier, charge enregistrée chez Kôrô BTP', 'Modéré', -1296000, 0, 'Mauvaise entité facturée'],
        ];
    }
}
```

- [ ] **Step 4: Enregistrer dans `DatabaseSeeder`**

Ouvrir `database/seeders/DatabaseSeeder.php`, ajouter dans `run()` :

```php
$this->call(RecruitmentFiscalisteSeeder::class);
```

(avec `use Database\Seeders\RecruitmentFiscalisteSeeder;` — déjà dans le même namespace, pas d'import nécessaire si même dossier).

- [ ] **Step 5: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=RecruitmentFiscalisteSeederTest`
Expected: PASS (3 tests, dont le score parfait 54/54).

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint database/seeders/RecruitmentFiscalisteSeeder.php
git add database/seeders/RecruitmentFiscalisteSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/RecruitmentFiscalisteSeederTest.php
git commit -m "feat(recrutement): seed du contenu Fiscaliste Groupe (18 transactions + corrigé)"
```

---

### Task 5: Contrôleur public + FormRequests + routes

**Files:**
- Create: `app/Http/Requests/Public/StartRecruitmentCandidatureRequest.php`
- Create: `app/Http/Requests/Public/SaveRecruitmentReponseRequest.php`
- Create: `app/Http/Controllers/Public/RecruitmentController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PublicRecruitmentTest.php`

**Interfaces:**
- Consumes: `RecruitmentService` (Task 3), modèles (Task 1). Vues `public.recruitment.show/test/closed/merci` produites en Task 6 (ce test-ci les référence donc Task 6 doit suivre immédiatement — voir note en Step 1).
- Produces: routes nommées `public.recruitment.show|store|test|answer|submit`, consommées par Task 6 (assets JS) et testées ici en HTTP.

- [ ] **Step 1: Écrire le test (échoue, routes/vues inexistantes)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PublicRecruitmentTest extends TestCase
{
    use RefreshDatabase;

    private function test(): RecruitmentTest
    {
        return RecruitmentTest::factory()->create(['slug' => 'fiscaliste-groupe', 'duree_minutes' => 45]);
    }

    public function test_la_page_du_poste_saffiche(): void
    {
        $test = $this->test();

        $this->get(route('public.recruitment.show', $test->slug))
            ->assertOk()
            ->assertSee($test->titre);
    }

    public function test_un_poste_inactif_renvoie_404(): void
    {
        $test = RecruitmentTest::factory()->create(['slug' => 'inactif', 'actif' => false]);

        $this->get(route('public.recruitment.show', $test->slug))->assertNotFound();
    }

    public function test_demarrer_le_test_redirige_vers_la_page_de_test(): void
    {
        $test = $this->test();

        $response = $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'candidat@exemple.ci',
        ]);

        $candidature = RecruitmentCandidature::where('email', 'candidat@exemple.ci')->firstOrFail();
        $response->assertRedirect(route('public.recruitment.test', ['test' => $test->slug, 'token' => $candidature->token]));
    }

    public function test_un_email_deja_utilise_est_rejete(): void
    {
        $test = $this->test();
        $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'deja@exemple.ci',
        ]);

        $response = $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Autre', 'prenom' => 'Personne', 'email' => 'deja@exemple.ci',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertSame(1, RecruitmentCandidature::where('email', 'deja@exemple.ci')->count());
    }

    public function test_la_page_de_test_affiche_les_questions_et_accepte_lautosave(): void
    {
        $test = $this->test();
        $question = RecruitmentQuestion::factory()->for($test)->create();
        $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'autosave@exemple.ci',
        ]);
        $candidature = RecruitmentCandidature::where('email', 'autosave@exemple.ci')->firstOrFail();

        $this->get(route('public.recruitment.test', ['test' => $test->slug, 'token' => $candidature->token]))
            ->assertOk()
            ->assertSee($question->contexte['id']);

        $answer = $this->patchJson(
            route('public.recruitment.answer', ['test' => $test->slug, 'token' => $candidature->token, 'question' => $question->id]),
            ['classement' => 'Majeur', 'ajustement_tva' => -168000, 'retenue_attendue' => 0],
        );
        $answer->assertOk()->assertJson(['saved' => true]);
        $this->assertDatabaseHas('recruitment_reponses', [
            'recruitment_candidature_id' => $candidature->id,
            'recruitment_question_id' => $question->id,
            'classement_saisi' => 'Majeur',
        ]);
    }

    public function test_soumettre_affiche_la_page_de_confirmation_sans_score(): void
    {
        $test = $this->test();
        $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'soumis@exemple.ci',
        ]);
        $candidature = RecruitmentCandidature::where('email', 'soumis@exemple.ci')->firstOrFail();

        $response = $this->post(route('public.recruitment.submit', ['test' => $test->slug, 'token' => $candidature->token]));

        $response->assertOk();
        $response->assertDontSee((string) $candidature->fresh()->score);
    }

    public function test_repondre_apres_expiration_renvoie_410(): void
    {
        $test = $this->test();
        $question = RecruitmentQuestion::factory()->for($test)->create();
        $this->post(route('public.recruitment.store', $test->slug), [
            'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'expire@exemple.ci',
        ]);
        $candidature = RecruitmentCandidature::where('email', 'expire@exemple.ci')->firstOrFail();
        $candidature->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

        $response = $this->patchJson(
            route('public.recruitment.answer', ['test' => $test->slug, 'token' => $candidature->token, 'question' => $question->id]),
            ['classement' => 'Majeur'],
        );

        $response->assertStatus(410);
    }
}
```

> Ce test dépend des vues créées en Task 6. Si exécuté avant, il échouera sur `assertOk()`/`assertSee()` (vue introuvable) plutôt que sur le contrôleur — c'est attendu, voir Step 2.

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=PublicRecruitmentTest`
Expected: FAIL (routes inexistantes : `Route [public.recruitment.show] not defined`).

- [ ] **Step 3: Créer les FormRequests**

`app/Http/Requests/Public/StartRecruitmentCandidatureRequest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/** Identité déclarée par le candidat au démarrage du test (sans compte). */
class StartRecruitmentCandidatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:191'],
            'prenom' => ['required', 'string', 'max:191'],
            'email' => ['required', 'string', 'email', 'max:191'],
            'telephone' => ['nullable', 'string', 'max:40'],
        ];
    }
}
```

`app/Http/Requests/Public/SaveRecruitmentReponseRequest.php` :

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Autosave d'une réponse. Tous les champs sont `nullable` : le candidat peut
 * vider un champ (annuler une saisie) et enregistrer un état partiel.
 */
class SaveRecruitmentReponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'classement' => ['nullable', 'string', Rule::in(['Conforme', 'Modéré', 'Majeur', 'Critique'])],
            'ajustement_tva' => ['nullable', 'integer'],
            'retenue_attendue' => ['nullable', 'integer'],
        ];
    }
}
```

- [ ] **Step 4: Créer le contrôleur**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\SaveRecruitmentReponseRequest;
use App\Http\Requests\Public\StartRecruitmentCandidatureRequest;
use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentTest;
use App\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Test de recrutement en ligne (sans compte). Flux : show() → store()
 * (démarrage) → test() (questions, autosave) → submit() (calcul de score,
 * jamais montré au candidat — voir RecruitmentService::notifyManagers()).
 */
class RecruitmentController extends Controller
{
    public function __construct(private readonly RecruitmentService $recruitment) {}

    public function show(RecruitmentTest $test): View
    {
        abort_unless($test->actif, 404);

        return view('public.recruitment.show', ['test' => $test]);
    }

    public function store(StartRecruitmentCandidatureRequest $request, RecruitmentTest $test): View|RedirectResponse
    {
        abort_unless($test->actif, 404);

        $candidature = $this->recruitment->startCandidature($test, $request->validated());

        if ($candidature === null) {
            return back()->withErrors(['email' => 'Vous avez déjà passé ce test.'])->withInput();
        }

        return redirect()->route('public.recruitment.test', ['test' => $test->slug, 'token' => $candidature->token]);
    }

    public function test(RecruitmentTest $test, string $token): View
    {
        abort_unless($test->actif, 404);

        $candidature = RecruitmentCandidature::where('recruitment_test_id', $test->id)
            ->where('token', $token)->firstOrFail();

        if (! $candidature->estEnCours()) {
            return view('public.recruitment.closed', ['candidature' => $candidature]);
        }

        $questions = $test->questions()->orderBy('ordre')->get();
        $reponses = $candidature->reponses()->get()->keyBy('recruitment_question_id');

        return view('public.recruitment.test', compact('test', 'candidature', 'questions', 'reponses'));
    }

    public function answer(
        SaveRecruitmentReponseRequest $request,
        RecruitmentTest $test,
        string $token,
        RecruitmentQuestion $question,
    ): JsonResponse {
        abort_unless($question->recruitment_test_id === $test->id, 404);

        $candidature = RecruitmentCandidature::where('recruitment_test_id', $test->id)
            ->where('token', $token)->firstOrFail();

        $reponse = $this->recruitment->saveReponse($candidature, $question, $request->validated());

        if ($reponse === null) {
            return response()->json(['message' => 'Session terminée ou expirée.'], 410);
        }

        return response()->json(['saved' => true]);
    }

    public function submit(RecruitmentTest $test, string $token): View
    {
        $candidature = RecruitmentCandidature::where('recruitment_test_id', $test->id)
            ->where('token', $token)->firstOrFail();

        $result = $this->recruitment->submit($candidature);

        if ($result === null) {
            return view('public.recruitment.closed', ['candidature' => $candidature]);
        }

        return view('public.recruitment.merci');
    }
}
```

- [ ] **Step 5: Ajouter les routes**

Dans `routes/web.php`, ajouter l'import `use App\Http\Controllers\Public\RecruitmentController;` et, à la suite du groupe `public.feedback.` (avant le groupe d'authentification), ajouter :

```php
/*
|--------------------------------------------------------------------------
| Test de recrutement en ligne (sans compte)
|--------------------------------------------------------------------------
| /recrutement/{slug} : présentation + démarrage ; /test/{token} : questions,
| autosave, soumission. Corrigé jamais chargé côté public (RecruitmentService).
*/
Route::prefix('recrutement')->name('public.recruitment.')->group(function (): void {
    Route::get('/{test:slug}', [RecruitmentController::class, 'show'])->name('show');
    Route::post('/{test:slug}', [RecruitmentController::class, 'store'])->name('store');
    Route::get('/{test:slug}/test/{token}', [RecruitmentController::class, 'test'])->name('test');
    Route::patch('/{test:slug}/test/{token}/questions/{question}', [RecruitmentController::class, 'answer'])->name('answer');
    Route::post('/{test:slug}/test/{token}/soumettre', [RecruitmentController::class, 'submit'])->name('submit');
});
```

- [ ] **Step 6: Créer les 4 vues minimales (pour que le test passe — vues détaillées en Task 6)**

`resources/views/public/recruitment/show.blade.php` :

```blade
<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>{{ $test->titre }}</title></head>
<body>
<h1>{{ $test->titre }}</h1>
<p>{{ $test->instructions }}</p>
<form method="POST" action="{{ route('public.recruitment.store', $test->slug) }}">
    @csrf
    @if ($errors->any())
        <p>{{ $errors->first() }}</p>
    @endif
    <input type="text" name="nom" value="{{ old('nom') }}" placeholder="Nom" required>
    <input type="text" name="prenom" value="{{ old('prenom') }}" placeholder="Prénom" required>
    <input type="email" name="email" value="{{ old('email') }}" placeholder="Email" required>
    <input type="tel" name="telephone" value="{{ old('telephone') }}" placeholder="Téléphone (optionnel)">
    <button type="submit">Commencer le test</button>
</form>
</body>
</html>
```

`resources/views/public/recruitment/test.blade.php` :

```blade
<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>{{ $test->titre }} — Test</title></head>
<body data-expires-at="{{ $candidature->expires_at->toIso8601String() }}">
<h1>{{ $test->titre }}</h1>
<div id="chrono"></div>
<form id="recrutement-form" method="POST" action="{{ route('public.recruitment.submit', ['test' => $test->slug, 'token' => $candidature->token]) }}">
    @csrf
    @foreach ($questions as $question)
        @php($reponse = $reponses->get($question->id))
        <fieldset data-question-id="{{ $question->id }}"
            data-answer-url="{{ route('public.recruitment.answer', ['test' => $test->slug, 'token' => $candidature->token, 'question' => $question->id]) }}">
            <legend>{{ $question->contexte['id'] }} — {{ $question->contexte['tiers'] }} ({{ $question->contexte['nature'] }})</legend>
            <p>Entité : {{ $question->contexte['entite'] }} — Pièce : {{ $question->contexte['piece'] }} — Date : {{ $question->contexte['date'] }}</p>
            <p>Montant HT : {{ number_format($question->contexte['montant_ht'], 0, ',', ' ') }} FCFA — TVA comptabilisée : {{ number_format($question->contexte['tva_comptabilisee'], 0, ',', ' ') }} FCFA — Retenue opérée : {{ number_format($question->contexte['retenue_operee'], 0, ',', ' ') }} FCFA</p>
            <p>{{ $question->contexte['informations'] }}</p>

            <label>Classement
                <select name="classement" class="js-champ">
                    <option value="">—</option>
                    @foreach (['Conforme', 'Modéré', 'Majeur', 'Critique'] as $option)
                        <option value="{{ $option }}" @selected($reponse?->classement_saisi === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </label>
            <label>Ajustement TVA (FCFA)
                <input type="number" name="ajustement_tva" class="js-champ" value="{{ $reponse?->ajustement_tva_saisi }}">
            </label>
            <label>Retenue attendue (FCFA)
                <input type="number" name="retenue_attendue" class="js-champ" value="{{ $reponse?->retenue_saisie }}">
            </label>
        </fieldset>
    @endforeach
    <button type="submit">Soumettre mes réponses</button>
</form>
<script>window.RECRUITMENT_CSRF = @json(csrf_token());</script>
<script src="{{ versioned_asset('js/recruitment.js') }}"></script>
</body>
</html>
```

`resources/views/public/recruitment/closed.blade.php` :

```blade
<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>Session terminée</title></head>
<body>
<h1>Cette session de test n'est plus disponible</h1>
<p>Le temps imparti est écoulé ou vos réponses ont déjà été transmises.</p>
</body>
</html>
```

`resources/views/public/recruitment/merci.blade.php` :

```blade
<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>Réponses transmises</title></head>
<body>
<h1>Merci</h1>
<p>Vos réponses ont bien été transmises. L'équipe recrutement reviendra vers vous.</p>
</body>
</html>
```

- [ ] **Step 7: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=PublicRecruitmentTest`
Expected: PASS (7 tests).

- [ ] **Step 8: Pint + commit**

```bash
./vendor/bin/pint app/Http/Requests/Public/StartRecruitmentCandidatureRequest.php app/Http/Requests/Public/SaveRecruitmentReponseRequest.php app/Http/Controllers/Public/RecruitmentController.php
git add app/Http/Requests/Public/StartRecruitmentCandidatureRequest.php app/Http/Requests/Public/SaveRecruitmentReponseRequest.php app/Http/Controllers/Public/RecruitmentController.php routes/web.php resources/views/public/recruitment tests/Feature/PublicRecruitmentTest.php
git commit -m "feat(recrutement): parcours public candidat (contrôleur + routes + vues minimales)"
```

---

### Task 6: Assets JS — autosave et chrono candidat

**Files:**
- Create: `public/js/recruitment.js`
- Modify: `resources/views/public/recruitment/test.blade.php:1-1` (déjà en place depuis Task 5, aucune modif de structure requise — le script est déjà référencé)

**Interfaces:**
- Consumes: attributs `data-expires-at` (body), `data-question-id`/`data-answer-url` (fieldset), classe `.js-champ`, route `public.recruitment.answer` (PATCH JSON), variable globale `window.RECRUITMENT_CSRF` (Task 5).
- Produces: comportement navigateur uniquement — pas d'interface consommée par une tâche ultérieure. Vérification manuelle (pas de test PHPUnit ; JS non couvert par la suite backend).

- [ ] **Step 1: Écrire `public/js/recruitment.js`**

```js
(function () {
    'use strict';

    var body = document.body;
    var expiresAt = new Date(body.dataset.expiresAt).getTime();
    var chrono = document.getElementById('chrono');
    var form = document.getElementById('recrutement-form');

    function tick() {
        var remaining = expiresAt - Date.now();
        if (remaining <= 0) {
            chrono.textContent = 'Temps écoulé — soumission en cours…';
            form.submit();
            return;
        }
        var minutes = Math.floor(remaining / 60000);
        var seconds = Math.floor((remaining % 60000) / 1000);
        chrono.textContent = 'Temps restant : ' + minutes + ' min ' + (seconds < 10 ? '0' : '') + seconds + ' s';
        window.setTimeout(tick, 1000);
    }

    tick();

    document.querySelectorAll('fieldset[data-answer-url]').forEach(function (fieldset) {
        var url = fieldset.dataset.answerUrl;

        fieldset.querySelectorAll('.js-champ').forEach(function (field) {
            field.addEventListener('change', function () {
                var payload = {
                    classement: fieldset.querySelector('[name="classement"]').value || null,
                    ajustement_tva: fieldset.querySelector('[name="ajustement_tva"]').value || null,
                    retenue_attendue: fieldset.querySelector('[name="retenue_attendue"]').value || null,
                };

                fetch(url, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': window.RECRUITMENT_CSRF,
                    },
                    body: JSON.stringify(payload),
                }).then(function (response) {
                    if (response.status === 410) {
                        window.location.reload();
                    }
                });
            });
        });
    });
})();
```

- [ ] **Step 2: Vérification manuelle**

Démarrer le serveur (`php artisan serve` ou `composer dev`), visiter `/recrutement/fiscaliste-groupe` (après avoir lancé `php artisan db:seed --class=RecruitmentFiscalisteSeeder` en local), démarrer une candidature, modifier un champ (vérifier dans l'onglet Réseau du navigateur que la requête `PATCH .../questions/{id}` part et renvoie `{"saved": true}`), attendre que le chrono affiche moins d'une minute pour confirmer visuellement le compte à rebours (pas besoin d'attendre 45 min réelles — modifier temporairement `expires_at` en base pour un test rapide, ou réduire `duree_minutes` sur un test de démo).

- [ ] **Step 3: Commit**

```bash
git add public/js/recruitment.js
git commit -m "feat(recrutement): autosave + chrono côté candidat (public/js/recruitment.js)"
```

---

### Task 7: Commande planifiée `recruitment:close-expired`

**Files:**
- Create: `app/Console/Commands/CloseExpiredRecruitmentCandidatures.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/CloseExpiredRecruitmentCandidaturesTest.php`

**Interfaces:**
- Consumes: `RecruitmentService::closeExpired()` (Task 3).
- Produces: commande Artisan `recruitment:close-expired`, planifiée chaque minute (aucune interface consommée par une tâche ultérieure).

- [ ] **Step 1: Écrire le test (échoue, commande inexistante)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RecruitmentCandidatureStatus;
use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CloseExpiredRecruitmentCandidaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_commande_cloture_les_candidatures_expirees(): void
    {
        $test = RecruitmentTest::factory()->create();
        $question = RecruitmentQuestion::factory()->for($test)->create([
            'reponse_attendue' => ['classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0, 'explication' => ''],
        ]);
        $candidature = RecruitmentCandidature::create([
            'recruitment_test_id' => $test->id, 'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'x@exemple.ci',
            'token' => bin2hex(random_bytes(20)),
            'started_at' => Carbon::now()->subHour(), 'expires_at' => Carbon::now()->subMinute(),
            'statut' => RecruitmentCandidatureStatus::EnCours,
        ]);

        $this->artisan('recruitment:close-expired')->assertSuccessful();

        $this->assertSame(RecruitmentCandidatureStatus::Expire, $candidature->fresh()->statut);
    }

    public function test_la_commande_est_idempotente(): void
    {
        $test = RecruitmentTest::factory()->create();
        RecruitmentCandidature::create([
            'recruitment_test_id' => $test->id, 'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'y@exemple.ci',
            'token' => bin2hex(random_bytes(20)),
            'started_at' => Carbon::now()->subHour(), 'expires_at' => Carbon::now()->subMinute(),
            'statut' => RecruitmentCandidatureStatus::EnCours,
        ]);

        $this->artisan('recruitment:close-expired');
        $this->artisan('recruitment:close-expired')->expectsOutputToContain('0 candidature');
    }
}
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=CloseExpiredRecruitmentCandidaturesTest`
Expected: FAIL (`Command "recruitment:close-expired" is not defined`).

- [ ] **Step 3: Implémenter la commande**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RecruitmentService;
use Illuminate\Console\Command;

/** Clôture et note les candidatures de recrutement dont le délai est écoulé. */
class CloseExpiredRecruitmentCandidatures extends Command
{
    protected $signature = 'recruitment:close-expired';

    protected $description = 'Clôture et note les candidatures de recrutement dont le temps est écoulé.';

    public function __construct(private readonly RecruitmentService $recruitment)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = $this->recruitment->closeExpired();
        $this->info("{$count} candidature(s) clôturée(s) pour expiration.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Planifier dans `routes/console.php`**

Ajouter à la suite des `Schedule::command(...)` existants :

```php
Schedule::command('recruitment:close-expired')->everyMinute()->withoutOverlapping();
```

- [ ] **Step 5: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=CloseExpiredRecruitmentCandidaturesTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint app/Console/Commands/CloseExpiredRecruitmentCandidatures.php
git add app/Console/Commands/CloseExpiredRecruitmentCandidatures.php routes/console.php tests/Feature/CloseExpiredRecruitmentCandidaturesTest.php
git commit -m "feat(recrutement): commande planifiée de clôture des candidatures expirées"
```

---

### Task 8: Admin — liste des postes + toggle actif

**Files:**
- Create: `app/Http/Controllers/Admin/RecruitmentTestController.php`
- Create: `resources/views/admin/recruitment/tests/index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/admin.blade.php`
- Test: `tests/Feature/AdminRecruitmentTestTest.php`

**Interfaces:**
- Consumes: `RecruitmentTest` (Task 1), rôle `role:admin_filiale,organisateur,super_admin`, `FilialeScope` (déjà actif via `booted()` du modèle).
- Produces: routes `admin.recrutement.index`, `admin.recrutement.toggle` — consommées par navigation (Task 10) et Task 9 (lien vers la liste des candidatures).

- [ ] **Step 1: Écrire le test (échoue, routes inexistantes)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Filiale;
use App\Models\RecruitmentTest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRecruitmentTestTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_adminfiliale_ne_voit_que_les_postes_de_sa_filiale(): void
    {
        $filialeA = Filiale::factory()->create();
        $filialeB = Filiale::factory()->create();
        RecruitmentTest::factory()->create(['filiale_id' => $filialeA->id, 'titre' => 'Poste A']);
        RecruitmentTest::factory()->create(['filiale_id' => $filialeB->id, 'titre' => 'Poste B']);
        $admin = User::factory()->filialeAdmin()->forFiliale($filialeA)->create();

        $response = $this->actingAs($admin)->get(route('admin.recrutement.index'));

        $response->assertOk()->assertSee('Poste A')->assertDontSee('Poste B');
    }

    public function test_un_agent_ne_peut_pas_acceder_a_la_liste(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)->get(route('admin.recrutement.index'))->assertForbidden();
    }

    public function test_toggle_actif_inverse_le_statut(): void
    {
        $filiale = Filiale::factory()->create();
        $admin = User::factory()->filialeAdmin()->forFiliale($filiale)->create();
        $test = RecruitmentTest::factory()->create(['filiale_id' => $filiale->id, 'actif' => true]);

        $this->actingAs($admin)->patch(route('admin.recrutement.toggle', $test->slug))->assertRedirect();

        $this->assertFalse($test->fresh()->actif);
    }
}
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=AdminRecruitmentTestTest`
Expected: FAIL (`Route [admin.recrutement.index] not defined`).

- [ ] **Step 3: Créer le contrôleur**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RecruitmentTest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Liste des postes de recrutement (scopée filiale) + activation/désactivation. */
class RecruitmentTestController extends Controller
{
    public function index(): View
    {
        $tests = RecruitmentTest::withCount('candidatures')->orderBy('titre')->get();

        return view('admin.recruitment.tests.index', ['tests' => $tests, 'nav' => 'recrutement']);
    }

    public function toggleActif(RecruitmentTest $test): RedirectResponse
    {
        $test->update(['actif' => ! $test->actif]);

        return back()->with('status', $test->actif ? 'Poste réactivé.' : 'Poste désactivé.');
    }
}
```

- [ ] **Step 4: Ajouter les routes admin**

Dans `routes/web.php`, ajouter l'import `use App\Http\Controllers\Admin\RecruitmentTestController;` et, à l'intérieur du groupe `admin.` existant (après le bloc Annuaire), ajouter :

```php
// Recrutement : postes de test en ligne, candidatures et résultats (Lot Recrutement).
Route::middleware('role:admin_filiale,organisateur,super_admin')->prefix('recrutement')->name('recrutement.')->group(function (): void {
    Route::get('/', [RecruitmentTestController::class, 'index'])->name('index');
    Route::patch('/tests/{test}/toggle', [RecruitmentTestController::class, 'toggleActif'])->name('toggle');
});
```

- [ ] **Step 5: Créer la vue liste**

`resources/views/admin/recruitment/tests/index.blade.php` :

```blade
@extends('layouts.admin')

@section('content')
<h1>Recrutement</h1>
@if (session('status'))
    <p>{{ session('status') }}</p>
@endif
<table>
    <thead>
        <tr><th>Poste</th><th>Statut</th><th>Candidatures</th><th></th></tr>
    </thead>
    <tbody>
        @foreach ($tests as $test)
            <tr>
                <td>{{ $test->titre }}</td>
                <td>{{ $test->actif ? 'Actif' : 'Inactif' }}</td>
                <td><a href="{{ route('admin.recrutement.candidatures.index', $test->slug) }}">{{ $test->candidatures_count }}</a></td>
                <td>
                    <form method="POST" action="{{ route('admin.recrutement.toggle', $test->slug) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit">{{ $test->actif ? 'Désactiver' : 'Activer' }}</button>
                    </form>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
```

> Référence `admin.recrutement.candidatures.index`, créée en Task 9.

- [ ] **Step 6: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=AdminRecruitmentTestTest`
Expected: PASS (3 tests). Si `test_un_agent_ne_peut_pas_acceder_a_la_liste` échoue avec une erreur de vue plutôt que 403, vérifier que le middleware `role` s'exécute bien AVANT le rendu (il le fait nativement, le contrôleur n'est jamais atteint).

- [ ] **Step 7: Pint + commit**

```bash
./vendor/bin/pint app/Http/Controllers/Admin/RecruitmentTestController.php
git add app/Http/Controllers/Admin/RecruitmentTestController.php resources/views/admin/recruitment/tests routes/web.php tests/Feature/AdminRecruitmentTestTest.php
git commit -m "feat(recrutement): liste admin des postes + activation/désactivation"
```

---

### Task 9: Admin — liste et détail des candidatures

**Files:**
- Create: `app/Http/Controllers/Admin/RecruitmentCandidatureController.php`
- Create: `resources/views/admin/recruitment/candidatures/index.blade.php`
- Create: `resources/views/admin/recruitment/candidatures/show.blade.php`
- Modify: `app/Models/RecruitmentCandidature.php:1-1` (ajouter la relation `test()` déjà présente — vérifier qu'elle porte bien le nom `test` pour `scopeBindings()`, déjà le cas depuis Task 1, aucune modification requise)
- Modify: `routes/web.php`
- Test: `tests/Feature/AdminRecruitmentCandidatureTest.php`

**Interfaces:**
- Consumes: `RecruitmentTest::candidatures()`, `RecruitmentScoringService::scoreReponse()` (Task 2, pour l'affichage correct/incorrect par champ), routes `admin.recrutement.index` (Task 8, lien retour).
- Produces: routes `admin.recrutement.candidatures.index`, `admin.recrutement.candidatures.show` — aucune interface consommée par une tâche ultérieure.

- [ ] **Step 1: Écrire le test (échoue, routes inexistantes)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RecruitmentCandidatureStatus;
use App\Models\Filiale;
use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentQuestion;
use App\Models\RecruitmentReponse;
use App\Models\RecruitmentTest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminRecruitmentCandidatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_liste_des_candidatures_saffiche_pour_le_bon_poste(): void
    {
        $filiale = Filiale::factory()->create();
        $admin = User::factory()->filialeAdmin()->forFiliale($filiale)->create();
        $test = RecruitmentTest::factory()->create(['filiale_id' => $filiale->id]);
        RecruitmentCandidature::create([
            'recruitment_test_id' => $test->id, 'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'x@exemple.ci',
            'token' => bin2hex(random_bytes(20)), 'started_at' => Carbon::now(), 'expires_at' => Carbon::now()->addMinutes(45),
            'statut' => RecruitmentCandidatureStatus::Soumis, 'score' => 45, 'submitted_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.recrutement.candidatures.index', $test->slug));

        $response->assertOk()->assertSee('Doe')->assertSee('45');
    }

    public function test_un_adminfiliale_dune_autre_filiale_ne_voit_pas_le_poste(): void
    {
        $filialeA = Filiale::factory()->create();
        $filialeB = Filiale::factory()->create();
        $adminB = User::factory()->filialeAdmin()->forFiliale($filialeB)->create();
        $test = RecruitmentTest::factory()->create(['filiale_id' => $filialeA->id]);

        $this->actingAs($adminB)->get(route('admin.recrutement.candidatures.index', $test->slug))->assertNotFound();
    }

    public function test_le_detail_affiche_la_reponse_du_candidat_face_a_lattendu(): void
    {
        $filiale = Filiale::factory()->create();
        $admin = User::factory()->filialeAdmin()->forFiliale($filiale)->create();
        $test = RecruitmentTest::factory()->create(['filiale_id' => $filiale->id]);
        $question = RecruitmentQuestion::factory()->for($test)->create([
            'contexte' => ['id' => 'T001', 'entite' => 'X', 'date' => '2026-06-03', 'piece' => 'FV-1', 'tiers' => 'Client', 'nature' => 'Vente', 'montant_ht' => 100, 'tva_comptabilisee' => 18, 'retenue_operee' => 0, 'informations' => 'RAS'],
            'reponse_attendue' => ['classement' => 'Conforme', 'ajustement_tva' => 0, 'retenue_attendue' => 0, 'explication' => 'OK'],
        ]);
        $candidature = RecruitmentCandidature::create([
            'recruitment_test_id' => $test->id, 'nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'x@exemple.ci',
            'token' => bin2hex(random_bytes(20)), 'started_at' => Carbon::now(), 'expires_at' => Carbon::now()->addMinutes(45),
            'statut' => RecruitmentCandidatureStatus::Soumis, 'score' => 3, 'submitted_at' => Carbon::now(),
        ]);
        RecruitmentReponse::create([
            'recruitment_candidature_id' => $candidature->id, 'recruitment_question_id' => $question->id,
            'classement_saisi' => 'Conforme', 'ajustement_tva_saisi' => 0, 'retenue_saisie' => 0,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.recrutement.candidatures.show', [$test->slug, $candidature->id]));

        $response->assertOk()->assertSee('T001')->assertSee('Conforme');
    }
}
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=AdminRecruitmentCandidatureTest`
Expected: FAIL (`Route [admin.recrutement.candidatures.index] not defined`).

- [ ] **Step 3: Créer le contrôleur**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RecruitmentCandidature;
use App\Models\RecruitmentTest;
use App\Services\RecruitmentScoringService;
use Illuminate\View\View;

/** Liste et détail des candidatures d'un poste de recrutement. */
class RecruitmentCandidatureController extends Controller
{
    public function __construct(private readonly RecruitmentScoringService $scoring) {}

    public function index(RecruitmentTest $test): View
    {
        $candidatures = $test->candidatures()->orderByDesc('created_at')->get();

        return view('admin.recruitment.candidatures.index', [
            'test' => $test, 'candidatures' => $candidatures, 'nav' => 'recrutement',
        ]);
    }

    public function show(RecruitmentTest $test, RecruitmentCandidature $candidature): View
    {
        $questions = $test->questions()->orderBy('ordre')->get();
        $reponses = $candidature->reponses()->get()->keyBy('recruitment_question_id');

        $detail = $questions->map(function ($question) use ($reponses) {
            $reponse = $reponses->get($question->id);

            return [
                'question' => $question,
                'reponse' => $reponse,
                'resultat' => $reponse !== null ? $this->scoring->scoreReponse($question, $reponse) : null,
            ];
        });

        return view('admin.recruitment.candidatures.show', [
            'test' => $test, 'candidature' => $candidature, 'detail' => $detail, 'nav' => 'recrutement',
        ]);
    }
}
```

- [ ] **Step 4: Ajouter les routes (nested, scopées au poste)**

Dans `routes/web.php`, ajouter l'import `use App\Http\Controllers\Admin\RecruitmentCandidatureController;` et, à la suite du groupe `recrutement.` créé en Task 8 :

```php
Route::middleware('role:admin_filiale,organisateur,super_admin')
    ->prefix('recrutement/tests/{test:slug}')->name('recrutement.candidatures.')
    ->scopeBindings()->group(function (): void {
        Route::get('/candidatures', [RecruitmentCandidatureController::class, 'index'])->name('index');
        Route::get('/candidatures/{candidature}', [RecruitmentCandidatureController::class, 'show'])->name('show');
    });
```

> `scopeBindings()` exige que `RecruitmentCandidature` expose une relation `test()` (créée en Task 1) pour contraindre `{candidature}` à appartenir au `{test}` de l'URL — une candidature d'un autre poste renvoie 404.

- [ ] **Step 5: Créer les vues**

`resources/views/admin/recruitment/candidatures/index.blade.php` :

```blade
@extends('layouts.admin')

@section('content')
<h1>{{ $test->titre }} — Candidatures</h1>
<a href="{{ route('admin.recrutement.index') }}">← Retour aux postes</a>
<table>
    <thead>
        <tr><th>Candidat</th><th>Email</th><th>Statut</th><th>Score</th><th>Soumis le</th></tr>
    </thead>
    <tbody>
        @foreach ($candidatures as $candidature)
            <tr>
                <td><a href="{{ route('admin.recrutement.candidatures.show', [$test->slug, $candidature->id]) }}">{{ $candidature->prenom }} {{ $candidature->nom }}</a></td>
                <td>{{ $candidature->email }}</td>
                <td>{{ $candidature->statut->label() }}</td>
                <td>{{ $candidature->score !== null ? $candidature->score.' / '.($test->questions()->count() * 3) : '—' }}</td>
                <td>{{ $candidature->submitted_at?->format('d/m/Y H:i') ?? '—' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
```

`resources/views/admin/recruitment/candidatures/show.blade.php` :

```blade
@extends('layouts.admin')

@section('content')
<h1>{{ $candidature->prenom }} {{ $candidature->nom }} — {{ $test->titre }}</h1>
<p>Score : {{ $candidature->score }} / {{ $test->questions()->count() * 3 }} — Statut : {{ $candidature->statut->label() }}</p>
<table>
    <thead>
        <tr><th>Transaction</th><th>Classement candidat</th><th>Classement attendu</th><th>TVA candidat</th><th>TVA attendue</th><th>Retenue candidat</th><th>Retenue attendue</th><th>Points</th></tr>
    </thead>
    <tbody>
        @foreach ($detail as $ligne)
            @php($attendu = $ligne['question']->reponse_attendue)
            <tr>
                <td>{{ $ligne['question']->contexte['id'] }}</td>
                <td>{{ $ligne['reponse']?->classement_saisi ?? '—' }}</td>
                <td>{{ $attendu['classement'] }}</td>
                <td>{{ $ligne['reponse']?->ajustement_tva_saisi ?? '—' }}</td>
                <td>{{ $attendu['ajustement_tva'] }}</td>
                <td>{{ $ligne['reponse']?->retenue_saisie ?? '—' }}</td>
                <td>{{ $attendu['retenue_attendue'] }}</td>
                <td>{{ $ligne['resultat']['points'] ?? 0 }} / 3</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
```

- [ ] **Step 6: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=AdminRecruitmentCandidatureTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Pint + commit**

```bash
./vendor/bin/pint app/Http/Controllers/Admin/RecruitmentCandidatureController.php
git add app/Http/Controllers/Admin/RecruitmentCandidatureController.php resources/views/admin/recruitment/candidatures routes/web.php tests/Feature/AdminRecruitmentCandidatureTest.php
git commit -m "feat(recrutement): liste et détail admin des candidatures"
```

---

### Task 10: Navigation admin

**Files:**
- Modify: `resources/views/layouts/admin.blade.php`
- Test: `tests/Feature/AdminRecruitmentNavTest.php`

**Interfaces:**
- Consumes: route `admin.recrutement.index` (Task 8), `$user->role` (`App\Enums\UserRole`).
- Produces: aucune (tâche terminale d'intégration UI).

- [ ] **Step 1: Écrire le test (échoue, lien absent)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRecruitmentNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_lien_recrutement_est_visible_pour_un_organisateur(): void
    {
        $user = User::factory()->create(); // Organisateur par défaut (UserFactory)

        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertSee(route('admin.recrutement.index'), false);
    }

    public function test_le_lien_recrutement_est_absent_pour_un_agent(): void
    {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)->get(route('admin.dashboard'))
            ->assertDontSee(route('admin.recrutement.index'), false);
    }
}
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

Run: `php artisan test --filter=AdminRecruitmentNavTest`
Expected: FAIL (lien absent du rendu — `assertSee` échoue).

- [ ] **Step 3: Ajouter le lien dans le nav**

Dans `resources/views/layouts/admin.blade.php`, juste après le lien `Annuaire` (avant le `@if($user->isSuperAdmin())` du bloc Holding), ajouter :

```blade
@unless ($user->role === \App\Enums\UserRole::Agent)
    <a href="{{ route('admin.recrutement.index') }}" class="{{ ($nav ?? '') === 'recrutement' ? 'active' : '' }}" @if(($nav ?? '')==='recrutement') aria-current="page" @endif>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Recrutement
    </a>
@endunless
```

- [ ] **Step 4: Lancer le test, vérifier le succès**

Run: `php artisan test --filter=AdminRecruitmentNavTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/admin.blade.php tests/Feature/AdminRecruitmentNavTest.php
git commit -m "feat(recrutement): lien de navigation vers le module Recrutement"
```

---

### Task 11: Vérification finale

**Files:** aucun fichier créé — validation transverse.

**Interfaces:** consomme l'ensemble des tâches précédentes ; ne produit rien pour une tâche ultérieure (dernière tâche du plan).

- [ ] **Step 1: Formater tout le code touché**

```bash
./vendor/bin/pint
```

- [ ] **Step 2: Lancer la suite complète**

```bash
composer test
```

Expected: tous les tests passent (les nouveaux + les 393 existants mentionnés dans la mémoire de session — aucune régression).

- [ ] **Step 3: Vérification manuelle bout-en-bout (voir skill `verify`)**

- `php artisan migrate:fresh --seed` en local.
- Ouvrir `/recrutement/fiscaliste-groupe`, démarrer une candidature, répondre à quelques transactions, vérifier l'autosave (Réseau navigateur), soumettre.
- Se connecter en AdminFiliale de la filiale par défaut, ouvrir Recrutement → voir le poste, cliquer sur les candidatures, ouvrir le détail et vérifier l'affichage correct/incorrect par champ.
- Vérifier qu'un compte d'une autre filiale ne voit ni le poste ni ses candidatures (404).

- [ ] **Step 4: Commit final si des ajustements ont été faits pendant la vérification**

```bash
git add -A
git commit -m "chore(recrutement): ajustements post-vérification"
```

(Ne committer que s'il y a effectivement des changements — sinon, sauter cette étape.)
