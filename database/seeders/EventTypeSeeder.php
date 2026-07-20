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
        $types = [
            ['name' => 'Atelier', 'color' => '#7c3aed'],
            ['name' => 'Réunion', 'color' => '#2563eb'],
            ['name' => 'Rencontre', 'color' => '#d6336c'],
            ['name' => 'Formation', 'color' => '#0e9e86'],
            ['name' => 'Conférence', 'color' => '#e0620d'],
        ];

        foreach ($types as $position => $type) {
            EventType::query()->updateOrCreate(
                ['name' => $type['name']],
                ['color' => $type['color'], 'is_active' => true, 'position' => $position],
            );
        }
    }
}
