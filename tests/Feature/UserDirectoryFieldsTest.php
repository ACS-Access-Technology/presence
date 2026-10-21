<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserDirectoryFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_colonnes_d_annuaire_existent(): void
    {
        foreach (['phone', 'direction', 'service', 'position', 'person_id'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('users', $column),
                "La colonne users.$column est manquante.",
            );
        }
    }
}
