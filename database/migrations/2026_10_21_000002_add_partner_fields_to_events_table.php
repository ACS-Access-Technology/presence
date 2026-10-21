<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rencontre partenaire : un événement peut être organisé pour le compte d'un
 * partenaire. Le destinataire de la feuille d'émargement est choisi À LA
 * CRÉATION de la rencontre (pas hérité dynamiquement du partenaire : modifier le
 * contact du partenaire ne redirige pas une feuille déjà promise à quelqu'un).
 * `partner_sheet_sent_at` rend l'envoi automatique idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('event_type_id')
                ->constrained('partners')->nullOnDelete();
            $table->string('partner_recipient_name', 150)->nullable()->after('partner_id');
            $table->string('partner_recipient_email', 191)->nullable()->after('partner_recipient_name');
            $table->timestamp('partner_sheet_sent_at')->nullable()->after('report_email_queued_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
            $table->dropColumn(['partner_recipient_name', 'partner_recipient_email', 'partner_sheet_sent_at']);
        });
    }
};
