<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Série d'événements (séances multiples, ex. formation sur 3 jours). Le
 * "gabarit" commun (titre, type, lieu, mode QR) vit ici ; chaque séance reste
 * un Event à part entière (son propre QR, sa propre liste de présence, son
 * propre compte-rendu) — la série ne fait que les regrouper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_series', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('event_type_id')->constrained('event_types')->restrictOnDelete();
            $table->string('location')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('event_series_id')->nullable()->after('id')
                ->constrained('event_series')->cascadeOnDelete();
            $table->unsignedSmallInteger('series_position')->nullable()->after('event_series_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_series_id');
            $table->dropColumn('series_position');
        });
        Schema::dropIfExists('event_series');
    }
};
