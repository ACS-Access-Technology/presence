<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Localisation obligatoire ou facultative, réglée PAR RÉUNION (défaut :
 * obligatoire). Le réglage du partenaire devient la valeur proposée à la
 * création de ses rencontres ; celui de la réunion fait foi. Reprise : les
 * rencontres partenaires existantes héritent du réglage actuel de leur partenaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('require_geolocation')->default(true)->after('grace_check_in_enabled');
        });

        DB::table('partners')->where('require_geolocation', false)->pluck('id')
            ->each(fn (int $id) => DB::table('events')->where('partner_id', $id)->update(['require_geolocation' => false]));
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('require_geolocation');
        });
    }
};
