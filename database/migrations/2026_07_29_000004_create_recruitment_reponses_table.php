<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réponse d'un candidat à une question (3 champs comparés au corrigé par
 * App\Services\RecruitmentScoringService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_reponses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_candidature_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recruitment_question_id')->constrained()->cascadeOnDelete();
            $table->string('classement_saisi', 20)->nullable();
            $table->bigInteger('ajustement_tva_saisi')->nullable();
            $table->bigInteger('retenue_saisie')->nullable();
            $table->timestamps();

            $table->unique(['recruitment_candidature_id', 'recruitment_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_reponses');
    }
};
