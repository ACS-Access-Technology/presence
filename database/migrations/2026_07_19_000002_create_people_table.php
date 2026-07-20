<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel unifié des personnes (clé = email, unique).
 *
 * Contient à la fois :
 *  - les visiteurs reconnus par email lors de l'émargement (is_staff = false) ;
 *  - le « Personnel ACS Groupe » importé en masse via Excel (is_staff = true),
 *    invitable à un événement.
 *
 * Ce sont les DERNIÈRES valeurs connues (profil réutilisable / pré-remplissage).
 * La copie figée déclarée POUR un événement vit dans attendances (snapshot).
 * Champs nullable sauf identité, car un import peut être partiel ; la validation
 * stricte se fait au niveau du formulaire d'émargement (snapshot attendance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique(); // normalisé en minuscules
            $table->string('last_name');
            $table->string('first_name');
            $table->string('phone')->nullable();
            $table->string('company')->nullable();   // entité / entreprise
            $table->string('direction')->nullable();
            $table->string('service')->nullable();
            $table->string('position')->nullable();  // poste ou fonction
            $table->boolean('is_staff')->default(false)->index(); // personnel interne ACS
            $table->string('source', 20)->default('emargement');  // App\Enums\PersonSource
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
