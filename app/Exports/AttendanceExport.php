<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

/**
 * Export XLSX de la liste de présence d'un événement (mêmes lignes/filtres
 * que l'export CSV — voir AttendanceController::filteredRows), signatures
 * incluses en image dans la dernière colonne.
 */
final class AttendanceExport implements FromArray, WithColumnFormatting, WithEvents, WithHeadings, WithMapping
{
    private const int SIGNATURE_COLUMN_INDEX = 13; // M — après les 12 colonnes de données

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
            'Saisie', 'Statut', 'Signature',
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
            '', // colonne Signature : image insérée via AfterSheet, pas de texte
        ];
    }

    /**
     * Téléphone en texte : sans ce format, Excel réinterprète certains numéros
     * comme des nombres (perte du "+" et des zéros de tête, notation scientifique
     * sur les plus longs), rendant l'extraction/le tri difficile.
     *
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['D' => NumberFormat::FORMAT_TEXT];
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $sheet->getColumnDimensionByColumn(self::SIGNATURE_COLUMN_INDEX)->setWidth(22);

                foreach ($this->rows as $i => $row) {
                    $rowNumber = $i + 2; // ligne 1 = en-têtes
                    $path = $row['signature_path'] ?? null;
                    if ($path === null || ! Storage::disk('local')->exists($path)) {
                        continue;
                    }

                    $sheet->getRowDimension($rowNumber)->setRowHeight(40);

                    $drawing = new Drawing();
                    $drawing->setPath(Storage::disk('local')->path($path));
                    $drawing->setHeight(36);
                    $drawing->setOffsetX(4);
                    $drawing->setOffsetY(2);
                    $drawing->setCoordinates(Coordinate::stringFromColumnIndex(self::SIGNATURE_COLUMN_INDEX).$rowNumber);
                    $drawing->setWorksheet($sheet);
                }
            },
        ];
    }
}
