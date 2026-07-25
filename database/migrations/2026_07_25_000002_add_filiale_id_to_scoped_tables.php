<?php

declare(strict_types=1);

use App\Models\Filiale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Étapes 2 à 4/5 de la migration multi-filiales (cadrage-multi-entites.md § 4.3).
 *
 * Ordre pensé pour un déploiement sans downtime sur MySQL mutualisé et pour
 * rester compatible SQLite (:memory: en test) :
 *   2. ajout de `filiale_id` NULLABLE sur users / events / event_types ;
 *   3. backfill de tout l'existant vers la filiale par défaut, puis migration
 *      du rôle `admin` → `super_admin` avec `filiale_id = NULL` (D-ME-6) ;
 *   4. passage NOT NULL + index (+ FK hors SQLite) sur events / event_types ;
 *      `users.filiale_id` reste NULLABLE (le SuperAdmin n'a pas de filiale).
 *
 * ⚠️ La FK n'est posée que hors SQLite : SQLite ne sait pas ajouter une clé
 * étrangère à une table existante. En production (MySQL) la contrainte
 * d'intégrité est bien créée ; en test l'index suffit à valider le backfill
 * et le comportement applicatif. L'unicité `(filiale_id, name)` de `event_types`
 * est laissée au Lot F (paramétrage par filiale) — non touchée ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Étape 2 : colonnes nullable (aucune contrainte encore, backfill à suivre).
        Schema::table('users', fn (Blueprint $t) => $t->unsignedBigInteger('filiale_id')->nullable());
        Schema::table('events', fn (Blueprint $t) => $t->unsignedBigInteger('filiale_id')->nullable());
        Schema::table('event_types', fn (Blueprint $t) => $t->unsignedBigInteger('filiale_id')->nullable());

        // Étape 3 : backfill vers la filiale par défaut « ACS Groupe ».
        $defaultId = Filiale::defaultId();
        DB::table('users')->whereNull('filiale_id')->update(['filiale_id' => $defaultId]);
        DB::table('events')->whereNull('filiale_id')->update(['filiale_id' => $defaultId]);
        DB::table('event_types')->whereNull('filiale_id')->update(['filiale_id' => $defaultId]);

        // Migration du rôle : 'admin' → 'super_admin', et retour à filiale_id NULL
        // (le SuperAdmin est au-dessus des filiales, D-ME-6). Fait APRÈS le backfill
        // pour écraser le rattachement par défaut qu'on vient de poser.
        DB::table('users')->where('role', 'admin')->update([
            'role' => 'super_admin',
            'filiale_id' => null,
        ]);

        // Étape 4 : NOT NULL + index sur les tables cloisonnées.
        Schema::table('events', function (Blueprint $t) {
            $t->unsignedBigInteger('filiale_id')->nullable(false)->change();
            $t->index('filiale_id');
        });
        Schema::table('event_types', function (Blueprint $t) {
            $t->unsignedBigInteger('filiale_id')->nullable(false)->change();
            $t->index('filiale_id');
        });
        Schema::table('users', function (Blueprint $t) {
            // Reste nullable (cas SuperAdmin) ; on indexe pour le scoping.
            $t->index('filiale_id');
        });

        // FK d'intégrité — hors SQLite uniquement (voir docblock).
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('events', fn (Blueprint $t) => $t->foreign('filiale_id')->references('id')->on('filiales')->restrictOnDelete());
            Schema::table('event_types', fn (Blueprint $t) => $t->foreign('filiale_id')->references('id')->on('filiales')->restrictOnDelete());
            Schema::table('users', fn (Blueprint $t) => $t->foreign('filiale_id')->references('id')->on('filiales')->nullOnDelete());
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('events', fn (Blueprint $t) => $t->dropForeign(['filiale_id']));
            Schema::table('event_types', fn (Blueprint $t) => $t->dropForeign(['filiale_id']));
            Schema::table('users', fn (Blueprint $t) => $t->dropForeign(['filiale_id']));
        }

        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('filiale_id'));
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn('filiale_id'));
        Schema::table('event_types', fn (Blueprint $t) => $t->dropColumn('filiale_id'));
    }
};
