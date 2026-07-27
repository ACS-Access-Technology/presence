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
