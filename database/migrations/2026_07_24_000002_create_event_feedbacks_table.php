<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avis post-événement (note + commentaire libre), un par présence — accessible
 * via un lien public keyé sur la référence de présence, envoyé dans l'email de
 * confirmation. Anonyme au sens où il n'exige pas de nouvelle authentification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('attendance_id')->unique()->constrained('attendances')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating'); // 1 à 5
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_feedbacks');
    }
};
