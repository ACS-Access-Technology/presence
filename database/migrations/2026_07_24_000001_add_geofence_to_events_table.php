<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Périmètre anti-fraude optionnel : coordonnées du lieu + rayon toléré (mètres).
 * Nullable et facultatif — un événement sans périmètre configuré n'impose aucun
 * contrôle de proximité (comportement antérieur inchangé, rétro-compatible).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->decimal('geofence_latitude', 10, 7)->nullable()->after('location');
            $table->decimal('geofence_longitude', 10, 7)->nullable()->after('geofence_latitude');
            $table->unsignedInteger('geofence_radius_m')->nullable()->after('geofence_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['geofence_latitude', 'geofence_longitude', 'geofence_radius_m']);
        });
    }
};
