<?php

declare(strict_types=1);

namespace App\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Génération d'images QR en SVG (aucune dépendance imagick/GD requise —
 * compatible hébergement mutualisé). Le QR encode l'URL d'émargement.
 */
final class QrImageService
{
    /** Rendu SVG brut du QR encodant $data. */
    public function svg(string $data, int $size = 320): string
    {
        return (new Builder(
            writer: new SvgWriter(),
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 8,
        ))->build()->getString();
    }

    /** Rendu SVG encodé en data URI (pratique pour l'injecter côté client). */
    public function svgDataUri(string $data, int $size = 320): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($data, $size));
    }
}
