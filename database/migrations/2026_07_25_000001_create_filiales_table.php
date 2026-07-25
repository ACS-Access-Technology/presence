<?php

declare(strict_types=1);

use App\Models\Filiale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 1/5 de la migration multi-filiales (cadrage-multi-entites.md § 4.3).
 *
 * Crée la table `filiales` et insère la filiale par défaut « ACS Groupe » à
 * laquelle tout l'existant sera rattaché (D-ME-2). Zéro action manuelle au
 * déploiement : la filiale existe dès la fin de la migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filiales', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('filiales')->insert([
            'name' => 'ACS Groupe',
            'slug' => Filiale::DEFAULT_SLUG,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('filiales');
    }
};
