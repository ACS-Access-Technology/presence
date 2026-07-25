<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot F (T-ME-20) : l'unicité des types d'événement passe de `name` GLOBALE à
 * `(filiale_id, name)`. Chaque filiale gère son propre référentiel de types
 * (Q-ME-10 : pas de socle partagé holding). Deux filiales peuvent donc avoir un
 * type de même nom sans collision.
 *
 * La colonne `filiale_id` a déjà été ajoutée et backfillée (migration
 * add_filiale_id_to_scoped_tables). On se contente ici de remplacer l'index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_types', function (Blueprint $table): void {
            $table->dropUnique(['name']);          // event_types_name_unique
            $table->unique(['filiale_id', 'name']); // unicité par filiale
        });
    }

    public function down(): void
    {
        Schema::table('event_types', function (Blueprint $table): void {
            $table->dropUnique(['filiale_id', 'name']);
            $table->unique(['name']);
        });
    }
};
