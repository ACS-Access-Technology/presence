<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Question d'un test (une transaction du cas Fiscaliste, par ex.). `contexte` =
 * données affichées au candidat ; `reponse_attendue` = corrigé, jamais exposé
 * côté public (voir App\Http\Controllers\Public\RecruitmentController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_test_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('ordre');
            $table->json('contexte');
            $table->json('reponse_attendue');
            $table->unsignedInteger('tolerance')->default(0);
            $table->timestamps();

            $table->unique(['recruitment_test_id', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_questions');
    }
};
