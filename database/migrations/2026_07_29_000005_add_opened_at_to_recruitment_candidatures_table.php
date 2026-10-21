<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Session unique : la page de test ne peut être chargée qu'une fois. Un
 * deuxième chargement (rafraîchissement, réouverture du lien) abandonne la
 * candidature (voir RecruitmentService::registerPageOpen()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_candidatures', function (Blueprint $table) {
            $table->dateTime('opened_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_candidatures', function (Blueprint $table) {
            $table->dropColumn('opened_at');
        });
    }
};
