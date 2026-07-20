<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesSignature;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saisie manuelle d'une présence par un organisateur (visiteur sans smartphone).
 * Pas de géolocalisation, mais la signature manuscrite reste obligatoire : le
 * visiteur signe sur l'appareil de l'organisateur avant validation.
 */
class StoreManualAttendanceRequest extends FormRequest
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
            'email' => ['nullable', 'string', 'email', 'max:191'],
            'last_name' => ['required', 'string', 'max:191'],
            'first_name' => ['required', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:191'],
            'company' => ['required', 'string', 'max:191'],
            'direction' => ['required', 'string', 'max:191'],
            'service' => ['nullable', 'string', 'max:191'],
            'position' => ['required', 'string', 'max:191'],
            'manual_confirmed' => ['accepted'],
            'signature' => $this->signatureRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'manual_confirmed.accepted' => 'Vous devez confirmer manuellement la présence.',
            'signature.required' => 'La signature du visiteur est obligatoire.',
        ];
    }
}
