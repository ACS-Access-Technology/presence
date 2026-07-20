<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Export XLSX de la liste de présence d'un événement (mêmes lignes/filtres
 * que l'export CSV — voir AttendanceController::filteredRows).
 */
final class AttendanceExport implements FromArray, WithHeadings, WithMapping
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows) {}

    /** @return list<array<string, mixed>> */
    public function array(): array
    {
        return $this->rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return [
            'Nom', 'Prénom', 'Email', 'Téléphone', 'Entité/Entreprise',
            'Direction', 'Service', 'Poste', 'Heure arrivée', 'Heure départ',
            'Saisie', 'Statut',
        ];
    }

    /** @return list<mixed> */
    public function map($row): array
    {
        return [
            $row['last_name'], $row['first_name'], $row['email'], $row['phone'], $row['company'],
            $row['direction'], $row['service'], $row['position'], $row['time'], $row['left'] ?? '',
            $row['manual'] ? 'Manuelle' : 'QR',
            $row['recurrent'] ? 'Récurrent' : 'Nouveau',
        ];
    }
}
