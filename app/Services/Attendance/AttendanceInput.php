<?php

declare(strict_types=1);

namespace App\Services\Attendance;

/**
 * Données validées d'un émargement (public ou saisie manuelle organisateur).
 * Objet immuable : la validation d'entrée est faite en amont (FormRequest).
 */
final readonly class AttendanceInput
{
    public function __construct(
        public string $email,
        public string $lastName,
        public string $firstName,
        public ?string $phone = null,
        public ?string $company = null,
        public ?string $direction = null,
        public ?string $service = null,
        public ?string $position = null,
        public ?string $signaturePath = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $accuracy = null,
        public bool $isManual = false,
        public bool $manualConfirmed = false,
        public ?int $recordedBy = null,
    ) {}
}
