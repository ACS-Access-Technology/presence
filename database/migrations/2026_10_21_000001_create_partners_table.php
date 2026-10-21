<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partenaire (client externe) pour le compte duquel ACS organise une rencontre :
 * son identité visuelle (nom, logo, couleurs) remplace celle de la filiale sur
 * toutes les surfaces de ses rencontres. Cloisonné par filiale (FilialeScope).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('filiale_id')->constrained('filiales')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('logo_path');
            $table->string('primary_color', 7);
            $table->string('secondary_color', 7)->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_email', 191)->nullable();
            $table->timestamps();

            $table->unique(['filiale_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
