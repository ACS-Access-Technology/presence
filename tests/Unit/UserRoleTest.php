<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_les_trois_roles_existent_avec_leurs_valeurs(): void
    {
        $this->assertSame('super_admin', UserRole::SuperAdmin->value);
        $this->assertSame('admin_filiale', UserRole::AdminFiliale->value);
        $this->assertSame('organisateur', UserRole::Organisateur->value);
    }

    public function test_labels_fr(): void
    {
        $this->assertSame('Super administrateur', UserRole::SuperAdmin->label());
        $this->assertSame('Administrateur de filiale', UserRole::AdminFiliale->label());
        $this->assertSame('Organisateur', UserRole::Organisateur->label());
    }

    public function test_is_super_admin(): void
    {
        $this->assertTrue(UserRole::SuperAdmin->isSuperAdmin());
        $this->assertFalse(UserRole::AdminFiliale->isSuperAdmin());
        $this->assertFalse(UserRole::Organisateur->isSuperAdmin());
    }

    public function test_is_filiale_admin(): void
    {
        $this->assertTrue(UserRole::AdminFiliale->isFilialeAdmin());
        $this->assertFalse(UserRole::SuperAdmin->isFilialeAdmin());
        $this->assertFalse(UserRole::Organisateur->isFilialeAdmin());
    }

    public function test_can_manage_settings_ouvert_aux_admins_seulement(): void
    {
        $this->assertTrue(UserRole::SuperAdmin->canManageSettings());
        $this->assertTrue(UserRole::AdminFiliale->canManageSettings());
        $this->assertFalse(UserRole::Organisateur->canManageSettings());
    }

    public function test_ancien_role_admin_nexiste_plus(): void
    {
        $this->assertNull(UserRole::tryFrom('admin'));
    }
}
