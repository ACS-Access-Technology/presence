<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partenaire institutionnel (administration ivoirienne) : ses rencontres portent
 * un en-tête officiel — armoiries de la Côte d'Ivoire à gauche, logo du
 * partenaire au centre, nom à droite. Coché une fois sur la fiche partenaire,
 * hérité par toutes ses rencontres (décision Férié du 2026-10-21).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('is_institutional')->default(false)->after('secondary_color');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('is_institutional');
        });
    }
};
