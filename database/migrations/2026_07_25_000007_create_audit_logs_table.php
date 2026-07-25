<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'audit minimal (premier besoin : traçabilité du transfert d'un
 * événement entre filiales, T-ME-14). Volontairement générique mais SIMPLE
 * (YAGNI) : acteur, action, sujet, données avant/après, horodatage. Ce n'est PAS
 * un système de journalisation d'événements applicatifs complet — juste une
 * trace immuable des opérations sensibles réservées au SuperAdmin.
 *
 * Append-only : pas de colonne `updated_at` (une entrée d'audit ne se modifie
 * jamais). L'acteur est dénormalisé (nom/email au moment de l'action) pour que
 * la trace survive à la suppression du compte (`user_id` passe alors à NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();

            // Acteur : FK conservée si le compte existe encore, + snapshot durable.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();

            $table->string('action')->index(); // ex. « event.transferred »

            // Sujet visé (morph léger, sans contrainte FK : le sujet peut être
            // supprimé sans faire disparaître sa trace d'audit).
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Instantanés structurés (ancienne/nouvelle valeur) — JSON libre.
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
