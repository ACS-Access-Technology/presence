<?php

declare(strict_types=1);

use App\Models\Filiale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot F (T-ME-19) : refonte de `settings` d'une PK globale `key` vers un modèle
 * scopé par filiale `(filiale_id, key)`. Le branding (nom, logo, couleur) devient
 * paramétrable par filiale avec repli sur la holding (Q-ME-8) ; le fuseau horaire
 * et le format de date restent GLOBAUX (stockés sous la filiale par défaut,
 * jamais surchargeables — cadrage § 9 Q-ME-8).
 *
 * Migration portable SQLite (:memory: en test) + MySQL (prod) : on ne peut pas
 * changer une PMK `string` en place proprement sur les deux moteurs, donc on
 * recrée la table (table temporaire → copie de l'existant rattaché à la filiale
 * par défaut → bascule). Aucune donnée perdue : le branding actuel est préservé.
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaultId = Filiale::defaultId();

        Schema::create('settings_tmp', function (Blueprint $table) use ($defaultId): void {
            $table->id();
            $table->unsignedBigInteger('filiale_id')->default($defaultId);
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['filiale_id', 'key']);
            $table->index('filiale_id');
        });

        // Copie de l'existant → rattaché à la filiale par défaut (holding).
        foreach (DB::table('settings')->get() as $row) {
            DB::table('settings_tmp')->insert([
                'filiale_id' => $defaultId,
                'key' => $row->key,
                'value' => $row->value,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }

        Schema::drop('settings');
        Schema::rename('settings_tmp', 'settings');

        // FK d'intégrité — hors SQLite (voir add_filiale_id_to_scoped_tables).
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('settings', fn (Blueprint $t) => $t->foreign('filiale_id')
                ->references('id')->on('filiales')->cascadeOnDelete());
        }
    }

    public function down(): void
    {
        Schema::create('settings_legacy', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Repli : on ne conserve que le branding de la filiale par défaut (holding).
        $defaultId = Filiale::defaultId();
        foreach (DB::table('settings')->where('filiale_id', $defaultId)->get() as $row) {
            DB::table('settings_legacy')->updateOrInsert(
                ['key' => $row->key],
                ['value' => $row->value, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at],
            );
        }

        Schema::drop('settings');
        Schema::rename('settings_legacy', 'settings');
    }
};
