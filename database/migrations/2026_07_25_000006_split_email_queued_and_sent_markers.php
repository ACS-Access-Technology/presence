<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sépare « mise en file » et « envoi réellement confirmé » pour les emails de
 * confirmation de présence (audit cycle de vie email, bugs #2/#1).
 *
 * Avant : `report_email_sent_at` (Event) et `confirmation_email_sent_at`
 * (Attendance) étaient posés DÈS la mise en file (`Mail::queue()`), avant même que
 * le job ait tourné. Le nom « sent » mentait : la base indiquait « envoyé » alors
 * que l'email pouvait encore échouer définitivement, sans trace ni retry possible.
 *
 * Après :
 * - `*_queued_at` (renommage de l'ancien `*_sent_at`) — posé immédiatement par le
 *   cron, signifie « un essai a été lancé » (sert de garde anti-doublon).
 * - `confirmation_email_sent_at` (nouvelle colonne) — posée par le job d'envoi
 *   lui-même, APRÈS le `send()` réussi ; c'est la seule vérité « réellement parti ».
 *
 * Côté Event, il n'existe pas d'« email d'événement » unique à confirmer (le récap
 * = un email PAR présence). `report_email_queued_at` reste donc le seul marqueur
 * pertinent au grain événement (« batch mis en file »). La vérité « réellement
 * livré » vit au grain présence, sur `Attendance.confirmation_email_sent_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->renameColumn('report_email_sent_at', 'report_email_queued_at');
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->renameColumn('confirmation_email_sent_at', 'confirmation_email_queued_at');
        });

        // Colonne ajoutée dans un second passage : le renommage a d'abord libéré
        // l'ancien nom (compatibilité SQLite, où enchaîner rename + add dans le même
        // Blueprint est fragile).
        Schema::table('attendances', function (Blueprint $table): void {
            $table->timestamp('confirmation_email_sent_at')->nullable()->after('confirmation_email_queued_at');
        });

        // Backfill : les lignes historiques suivaient l'ancien contrat « marqué dès
        // la mise en file = traité ». On préserve cette sémantique (queued ⇒ sent)
        // pour ne pas les faire apparaître comme « en file mais jamais envoyées »,
        // ni risquer un ré-envoi lors du prochain passage du cron.
        DB::table('attendances')
            ->whereNotNull('confirmation_email_queued_at')
            ->update(['confirmation_email_sent_at' => DB::raw('confirmation_email_queued_at')]);
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropColumn('confirmation_email_sent_at');
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->renameColumn('confirmation_email_queued_at', 'confirmation_email_sent_at');
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->renameColumn('report_email_queued_at', 'report_email_sent_at');
        });
    }
};
