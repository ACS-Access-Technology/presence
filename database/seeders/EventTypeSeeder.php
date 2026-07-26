<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EventType;
use Illuminate\Database\Seeder;

/**
 * Types d'événement par défaut, avec les couleurs exactes des prototypes validés.
 */
class EventTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (EventType::DEFAULTS as $position => $type) {
            EventType::query()->updateOrCreate(
                ['name' => $type['name']],
                ['color' => $type['color'], 'is_active' => true, 'position' => $position],
            );
        }
    }
}
