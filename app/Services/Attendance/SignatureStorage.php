<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Décode et stocke une signature manuscrite (data URI PNG) sur le disque privé.
 * Utilisé aussi bien par l'émargement public que par la saisie manuelle organisateur.
 */
final class SignatureStorage
{
    public static function store(int $eventId, string $dataUri): string
    {
        $base64 = substr($dataUri, strlen('data:image/png;base64,'));
        $binary = base64_decode($base64, true);

        // La validation (starts_with) garantit le préfixe ; on protège le décodage.
        if ($binary === false) {
            abort(422, 'Signature illisible.');
        }

        $path = 'signatures/'.$eventId.'/'.Str::uuid()->toString().'.png';
        Storage::disk('local')->put($path, $binary);

        return $path;
    }
}
