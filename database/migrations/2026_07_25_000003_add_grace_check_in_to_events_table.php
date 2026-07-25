<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot E — QR post-clôture avec délai de grâce (cadrage-multi-entites.md § 6).
 *
 * Opt-in par événement, désactivé par défaut. Quand il est activé, l'émargement
 * reste accepté jusqu'à `ends_at + 15 min` (constante applicative), et la clôture
 * + l'email récap sont reportés d'autant (voir Event::checkInClosesAt et
 * CloseDueEvents). Le statut affiché (« Clos » dès `ends_at`) ne change PAS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('grace_check_in_enabled')->default(false)->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('grace_check_in_enabled');
        });
    }
};
