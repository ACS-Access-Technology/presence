<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Http\Requests\Concerns\ValidatesSignature;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation d'un émargement public (formulaire visiteur).
 *
 * Le formulaire envoie TOUJOURS les champs d'identité (pour un récurrent, ils
 * sont pré-remplis depuis son profil puis renvoyés) : le snapshot de présence
 * reflète ainsi ce qui a été déclaré POUR cet événement.
 *
 * Géolocalisation et signature sont strictement obligatoires (aucun bypass).
 */
class StoreAttendanceRequest extends FormRequest
{
    use ValidatesSignature;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:191'],
            'last_name' => ['required', 'string', 'max:191'],
            'first_name' => ['required', 'string', 'max:191'],
            'phone' => ['required', 'string', 'max:191'],
            'company' => ['required', 'string', 'max:191'],
            'direction' => ['required', 'string', 'max:191'],
            'service' => ['nullable', 'string', 'max:191'],
            'position' => ['required', 'string', 'max:191'],

            // Géolocalisation obligatoire (position réelle du navigateur).
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],

            // Signature manuscrite obligatoire : data URI PNG, taille bornée.
            'signature' => $this->signatureRules(),

            // Preuve de scan (ticket signé émis à l'ouverture de la page).
            'ticket' => ['required', 'string'],

            // Case « mentions » cochée.
            'consent' => ['accepted'],

            // Choix anti-chevauchement : confirmation du départ de l'autre événement.
            'confirm_departure' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latitude.required' => 'La position est obligatoire pour valider votre présence.',
            'longitude.required' => 'La position est obligatoire pour valider votre présence.',
            'signature.required' => 'La signature est obligatoire.',
            'consent.accepted' => 'Vous devez reconnaître le traitement de vos données.',
        ];
    }
}
