<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations de personnel ACS Groupe à un événement.
 *
 * IMPORTANT : une invitation N'EST PAS une présence. C'est une liste d'attendus.
 * L'invité·e doit quand même scanner le QR et émarger normalement le jour J
 * (géoloc + signature). La liste de présence croisera invitations × attendances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('notified_at')->nullable(); // email d'invitation (hors périmètre actuel)
            $table->timestamps();

            $table->unique(['event_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_invitations');
    }
};
