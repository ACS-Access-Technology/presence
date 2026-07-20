<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Présences : une par (personne, événement). L'unicité (event_id, person_id) est
 * la clé d'anti-doublon / idempotence : une 2e soumission ne crée jamais de doublon.
 *
 * Les champs identité sont un SNAPSHOT immuable de ce qui a été déclaré POUR cet
 * événement (l'entité/entreprise peut différer d'un événement à l'autre, et
 * l'historique/export reste exact même si le profil Personne change ensuite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();

            // Snapshot identité (figé au moment de l'émargement).
            $table->string('last_name');
            $table->string('first_name');
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->string('direction')->nullable();
            $table->string('service')->nullable();
            $table->string('position')->nullable();

            // Preuves de présence (émargement standard).
            $table->string('signature_path')->nullable();      // PNG sur disque privé
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy', 8, 2)->nullable();     // mètres

            // Saisie manuelle par un organisateur (sans géoloc ni signature tactile).
            $table->boolean('is_manual')->default(false);
            $table->boolean('manual_confirmed')->default(false); // « présence confirmée manuellement »
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('checked_in_at');                 // horodatage SERVEUR, fait foi
            $table->dateTime('departed_at')->nullable();       // départ (auto-checkout ou manuel)
            $table->string('reference', 20)->unique();         // ex. PRS-4F2A9
            $table->timestamp('confirmation_email_sent_at')->nullable();

            $table->timestamps();

            $table->unique(['event_id', 'person_id']);
            $table->index(['event_id', 'checked_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
