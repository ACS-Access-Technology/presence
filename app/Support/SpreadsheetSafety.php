<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Neutralise l'injection de formule CSV/XLSX (CSV/DDE injection) : les champs
 * exportés proviennent du formulaire public non authentifié, donc un visiteur
 * peut y saisir "=HYPERLINK(...)" ou une formule DDE. Excel interprète toute
 * cellule commençant par =, +, -, @, tabulation ou retour chariot comme une
 * formule au moment de l'ouverture par l'organisateur.
 */
final class SpreadsheetSafety
{
    private const array DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function sanitizeCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        foreach (self::DANGEROUS_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return "'".$value;
            }
        }

        return $value;
    }

    /**
     * @param  list<mixed>  $row
     * @return list<mixed>
     */
    public static function sanitizeRow(array $row): array
    {
        return array_map(self::sanitizeCell(...), $row);
    }
}
