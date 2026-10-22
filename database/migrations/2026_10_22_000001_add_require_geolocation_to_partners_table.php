<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Localisation verrouillée (obligatoire, comportement historique) ou
 * déverrouillée (facultative) pour les rencontres d'un partenaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('require_geolocation')->default(true)->after('is_institutional');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('require_geolocation');
        });
    }
};
