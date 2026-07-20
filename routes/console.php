<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tâches planifiées
|--------------------------------------------------------------------------
| Une seule entrée cron cPanel suffit :
|   * * * * * cd /chemin/du/projet && php artisan schedule:run >> /dev/null 2>&1
|
| - Clôture les événements terminés + met en file les emails de confirmation.
| - Traite la file d'attente (base de données) sans worker persistant : chaque
|   minute, on vide la file puis on s'arrête (compatible hébergement mutualisé).
*/
Schedule::command('events:close-due')->everyMinute()->withoutOverlapping();

Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();
