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
            // 400 000 caractères ≈ 293 Ko décodés : très large pour un canevas de
            // signature (quelques dizaines de Ko en pratique), mais borne l'abus.
            'required', 'string', 'max:400000',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! str_starts_with($value, 'data:image/png;base64,')) {
                    $fail('Signature invalide.');

                    return;
                }

                $binary = base64_decode(substr($value, strlen('data:image/png;base64,')), true);
                $info = $binary === false ? false : @getimagesizefromstring($binary);

                // Le préfixe déclaré ne suffit pas : on vérifie que le contenu décodé
                // est réellement un PNG (pas un fichier arbitraire renommé).
                if ($info === false || $info[2] !== IMAGETYPE_PNG) {
                    $fail('Signature invalide.');
                }
            },
        ];
    }
}
