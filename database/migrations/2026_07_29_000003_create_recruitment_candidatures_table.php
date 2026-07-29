<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tentative d'un candidat sur un test (identité déclarée, pas de compte).
 * `token` : clé d'URL opaque, jamais un identifiant de session. Un seul essai
 * par email et par poste (contrainte unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_candidatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_test_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('prenom');
            $table->string('email');
            $table->string('telephone')->nullable();
            $table->string('token', 64)->unique();
            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->dateTime('submitted_at')->nullable();
            $table->string('statut', 20)->default('en_cours'); // App\Enums\RecruitmentCandidatureStatus
            $table->unsignedSmallInteger('score')->nullable();
            $table->timestamps();

            $table->unique(['recruitment_test_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_candidatures');
    }
};
