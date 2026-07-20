<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Compat MySQL < 8 / anciennes configs InnoDB : limite la longueur d'index
        // par défaut (191 = 767 octets en utf8mb4) pour éviter « key too long ».
        // MySQL local MAMP = 5.7 ; cible prod probable MySQL 8 / MariaDB 10+.
        Schema::defaultStringLength(191);

        // Rigueur en développement : lève une exception sur accès à un attribut
        // non chargé, une assignation de masse hors $fillable, etc.
        Model::shouldBeStrict($this->app->isLocal());
    }
}
