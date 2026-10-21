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
