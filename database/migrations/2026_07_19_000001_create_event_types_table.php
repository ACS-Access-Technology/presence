<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel des types d'événement (CRUD depuis Paramètres, admin only).
 * Chaque type porte une couleur de badge. Un type désactivé n'est plus proposé
 * à la création mais reste sur les événements passés ; suppression bloquée si utilisé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('color', 7); // couleur hex #RRGGBB (fond dérivé en CSS)
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_types');
    }
};
