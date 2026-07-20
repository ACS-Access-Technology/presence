<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Règle de validation commune pour une signature manuscrite (data URI PNG).
 * Partagée entre l'émargement public et la saisie manuelle organisateur.
 */
trait ValidatesSignature
{
    /**
     * @return array<int, mixed>
     */
    protected function signatureRules(): array
    {
        return [
            'required', 'string', 'max:2000000',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! str_starts_with($value, 'data:image/png;base64,')) {
                    $fail('Signature invalide.');
                }
            },
        ];
    }
}
