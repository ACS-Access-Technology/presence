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

                    return;
                }

                // Garde-fou côté serveur, indépendant de tout bug client (passé,
                // présent ou futur) : un PNG structurellement valide mais SANS
                // encre réelle (canevas resize côté navigateur qui l'a effacé,
                // etc.) ne doit jamais être accepté comme une vraie signature.
                if (! $this->signatureHasInk($binary)) {
                    $fail('La signature est vide. Veuillez signer avant de valider.');
                }
            },
        ];
    }

    /** Le canevas du pad de signature part transparent : tout pixel non totalement transparent = de l'encre. */
    private function signatureHasInk(string $binary): bool
    {
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $hasInk = false;

        for ($y = 0; $y < $height && ! $hasInk; $y += 2) {
            for ($x = 0; $x < $width; $x += 2) {
                $alpha = (imagecolorat($image, $x, $y) >> 24) & 0x7F;
                // GD : 0 = opaque, 127 = totalement transparent.
                if ($alpha < 127) {
                    $hasInk = true;
                    break;
                }
            }
        }

        imagedestroy($image);

        return $hasInk;
    }
}
