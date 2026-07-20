<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Événements ACS Groupe. Pas de mode brouillon : création = publication directe.
 * Statut (à venir / en cours / clos / annulé) DÉRIVÉ de l'horloge + cancelled_at/closed_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('event_type_id')->constrained('event_types')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('location')->nullable();

            $table->string('qr_mode', 20); // App\Enums\QrMode : 'statique' | 'tournant'
            // Secret HMAC pour le token tournant (sans état). Aléatoire, jamais exposé.
            $table->string('qr_secret', 64)->nullable();
            // Slug public stable de la page d'émargement (/e/{slug}).
            $table->string('public_slug')->unique();

            // Clôture (posée par le cron en fin de fenêtre) + trace de l'email auto.
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('report_email_sent_at')->nullable();

            // Annulation (réversible : dé-annuler = remettre à null).
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
