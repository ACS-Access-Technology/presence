<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des reports d'un événement (trace de l'ancien créneau).
 * Un report met à jour events.starts_at/ends_at ET insère une ligne ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->dateTime('old_starts_at');
            $table->dateTime('old_ends_at');
            $table->dateTime('new_starts_at');
            $table->dateTime('new_ends_at');
            $table->string('reason')->nullable();
            $table->foreignId('rescheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reschedules');
    }
};
